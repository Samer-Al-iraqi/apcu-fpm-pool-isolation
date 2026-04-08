# apcu-fpm-pool-isolation
APCu C-level key namespacing for PHP-FPM: Isolation of key spaces by pool name to prevent collisions and cross-pool data tampering

apcu is a fantastic in-memory key-value store extension; unlike other solutions, it doesn't require a separate PHP service, but rather runs within it, relying on PHP's own master process. It's very fast, simple, and excellent for many small and medium-sized applications.

The problem with apcu (and it's the same problem with other solutions like memcached ) is that it doesn't provide isolation between entries coming from different pools. The general rule is that each independent application has its own pool, allowing it to be isolated with its own system user and configuration. This provides high security if one application is compromised.

However, with apcu, entries cannot be isolated based on independent applications (pools). Any pool can delete or modify values from other pools, intentionally or unintentionally.

The usual solution is to isolate keys at the application level. Often, a name is created for the application, and functions are used to add a prefix or suffix to the key before calling apcu functions. This is not only an annoying addition that must be remembered when programming different applications, but it also doesn't protect against malicious interference.

This is where my idea came from: automatically isolating keys that are sent directly to apcu at the C level without application intervention. And that's exactly what I did.

## What I did
I patched the apcu extension directly at the C source level by introducing a transparent, zero-overhead memory hook that automatically namespaces every cache key based on the active PHP-FPM pool.

Instead of relying on PHP developers to manually prefix their keys—a practice that is tedious and entirely vulnerable to malicious scripts—I intercepted the Zend Engine's string parsing right before the keys are handed over to the APCu shared memory engine.

Here is how the architecture works under the hood:

1. Out-of-Band Pool Identification
When a PHP-FPM worker spawns, my code reads /proc/self/cmdline to safely extract the worker's exact pool name (e.g., php-fpm: pool tenant_A). It uses this name to generate a unique prefix. if the environment is heavily restricted (such as a strict chroot jail), the code gracefully falls back to using the Linux effective UID (geteuid()).

2. The "Zero-Allocation" Memory Magic
String formatting and memory allocation in C (malloc/free) are computationally expensive if performed on every single web request. To ensure this isolation patch didn't slow down APCu's legendary speed, I engineered a zero-allocation memory reuse strategy.

   - Worker-Lifetime Persistence: On the very first APCu call made by a worker process, the extension allocates a single, persistent zend_string buffer. This buffer is completely immune to PHP's garbage collector and lives for the entire lifespan of the FPM worker.

   - Fast Memory Copying: On every subsequent cache request, instead of allocating new heap memory, the code uses a raw memcpy to drop the user's requested key right into this persistent buffer, immediately following the statically held pool prefix.

   - Zend Engine Spoofing: Finally, the code dynamically mutates the struct's length (ZSTR_LEN) and forcefully resets its hash (h = 0). This tricks APCu into cleanly calculating a new hash for the securely combined string.

3. Absolute Transparency
To the PHP application, absolutely nothing has changed. A script in Pool A can call apcu_store('db_config', $data). A script in Pool B can call apcu_store('db_config', $data). Both applications will fetch their respective data perfectly, completely unaware that in the server's physical RAM, their keys are securely locked away as pool_A_db_config and pool_B_db_config.

By moving the security boundary down to the Zend Engine layer, this patch achieves true multi-tenant memory isolation without sacrificing a single microsecond of performance.

## How to install
Because this is a C-level patch, you must compile the extension from source (i.e. no pecl install). It only takes a few minutes.
1. Download the latest APCu release (currently 5.1.28) from the official PECL repository or GitHub, and extract it:
```bash
wget https://pecl.php.net/get/apcu-5.1.28.tgz
tar -xvf apcu-5.1.28.tgz
cd apcu-5.1.28
```
2. Copy the files from this repository into the extracted APCu directory, overwriting the default C file:
```bash
# Assuming you cloned this repo into /tmp/apcu-fpm-isolation
cp /tmp/apcu-fpm-isolation/samer.h .
cp /tmp/apcu-fpm-isolation/php_apc.c . # this one must be replaced
```
3. Prepare the build environment using phpize, then compile and install:
```bash
phpize # or /php-install-prefix/bin/phpize 
./configure # or ./configure --with-php-config=/php-install-prefix/bin/php-config
make clean
make
make install
```
4. Add the extension to your PHP configuration
> extension=apcu.so

then restart php-fpm service 
> systemctl restart php-fpm

### In case I stopped updating this repo
You can download the latest apcu release then apply the same changes that I did to the file **php_apc.c** as follow:
1. Add the following after other includes in the file
  ```c
  #include "samer.h"
  ```
2. Now search for the definition of each apcu functions that accept **key** parameter and find the point where apcu handed the zend string variable of the passed **key** and wrap it with the function **samerKey**:
  ```c
  # e.g. find
  apc_cache_stat(apc_user_cache, key, return_value);
  # replace it with:
  apc_cache_stat(apc_user_cache, samerKey(key), return_value);
  ```
3. for those functions that accept either string or array of strings, there will be two places to edit, the first is the place where apcu check that the passed zval is actually string, and the other place is where apcu loop through the array and process its values.

4. To learn about places where I applied the hack, just search **php_apc.c** file for **samerKey**

## Known Limitations & Caveats
While this extension successfully enforces cross-tenant memory isolation, system administrators should be aware of the following architectural realities:

1. This hook is specifically designed for PHP-FPM environments. If a script is executed via CLI, or if the server uses a different SAPI (like Apache mod_php), the prefixing mechanism is automatically disabled. In these environments, APCu will safely pass through the original keys and operate exactly as it does out-of-the-box.

3. The primary isolation method relies on extracting the FPM pool name via Linux's /proc/self/cmdline. On operating systems where procfs is unavailable or structured differently (e.g., FreeBSD, macOS), or if the worker is running inside a highly restrictive chroot jail, the extension will gracefully fall back to using the worker's effective User ID (geteuid()) as the prefix.

4. While this patch guarantees Data Integrity (Pool A cannot tamper with Pool B's keys), all data physically resides within the same shared memory segment (SHM). APCu does not support per-pool memory quotas. Therefore, a compromised or poorly coded application in one pool can maliciously or accidentally fill the entire cache, forcing APCu to evict legitimate keys belonging to other pools.

5. If any pool calls apcu_clear_cache(), the underlying engine will still flush the entire shared memory segment, wiping data for all pools. If you wish to prevent this behavior, you can disable the function entirely in your php.ini:

```ini
disable_functions = apcu_clear_cache
```
Alternatively, if you want to hardcode this restriction into the extension itself, locate PHP_FUNCTION(apcu_clear_cache) in php_apc.c and add RETURN_FALSE; to the very top of the function block:
```c
PHP_FUNCTION(apcu_clear_cache) {
    /* SAMER HACK: Prevent any pool from wiping the global cache */
    RETURN_FALSE; 
    
    // ... original code continues ...
}
```
then the only way to clear the entire apcu cache is to restart fpm service.
5. Because the APCUIterator reads directly from the raw C hash tables—bypassing the standard Zend Engine parameter parsing—it bypasses our string hook. A pool utilizing the iterator will be able to see the raw, prefixed keys (and values) belonging to other pools. If absolute strict multi-tenant secrecy is required, you can try disabling APCUIterator in php.ini:
```ini
disable_classes=APCUIterator
```
PHP has **warnning** about disabling classes 
6. The extension is highly optimized, initializing its persistent string buffer at 256 bytes (which covers 99.9% of real-world cache keys). If an application attempts to save an exceptionally long key (e.g., 1024+ bytes), the extension will safely and dynamically realloc the buffer to accommodate it. Because this buffer is persistent across the worker's lifetime, this memory reallocation only happens once per worker, ensuring zero performance penalty on all subsequent requests.


### Appendx: apcu functions that accept **key** parameter
- apcu_add
- apcu_cas
- apcu_dec
- apcu_delete
- apcu_entry
- apcu_exists
- apcu_fetch
- apcu_inc
- apcu_key_info
- apcu_store
