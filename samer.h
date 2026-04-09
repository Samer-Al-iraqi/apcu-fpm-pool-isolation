#ifndef SAMER_H
#define SAMER_H

#include <sys/types.h>
#include <unistd.h>
#include <stdio.h>
#include <string.h>
#include "zend_string.h"
#include "SAPI.h"

#ifdef ZTS
#error "samer.h is not compatible with ZTS (thread-safe PHP)"
#endif

#define SAMER_INIT_SIZE 256
/* Persistent worker-lifetime key buffer */
static zend_string *myKey = NULL;
static size_t prefix_len = 0, myKeyCap = SAMER_INIT_SIZE, disabled=0;


/*
 * Read the FPM pool name from /proc/self/cmdline.
 * FPM workers set their process title to "php-fpm: pool POOLNAME".
 * Writes "POOLNAME_" into buf. Returns bytes written, 0 on failure.
 */
static inline size_t samer_get_pool_prefix(char *buf) {

    FILE *f = fopen("/proc/self/cmdline", "r");
    if (!f) return 0;

    /* Read argv[0] (process title) up to the first null byte */
	int c, i=0;
	while((c = fgetc(f)) != EOF && c != '\0') {
		buf[i++] = (char)c;
		if(i==SAMER_INIT_SIZE){	i=0; break;	}
	}
	fclose(f);
	buf[i] = '\0';
	if(i<7) return 0;
	
    /* Parse "php-fpm: pool POOLNAME" */
	char *p = strstr(buf, "pool ");
    if (!p) return 0;
	p += 5; /* skip "pool " */
	c= i-(p - buf);
	if(c>SAMER_INIT_SIZE-2) return 0; // not enough space for pool name + '_'
	memmove(buf, p, c); // shift pool name to start of buffer
	buf[c++]='_';
	buf[c] = '\0';
	return (size_t)c;
}

static inline zend_string* samerKey(zend_string *orig_key) {

    /* First call in this worker: check SAPI and build prefix once */
    if (myKey == NULL) {
		if(disabled) return orig_key;
        if (strcmp(sapi_module.name, "fpm-fcgi") != 0) {
            disabled = 1;
            return orig_key;
        }
        myKey = zend_string_alloc(myKeyCap, 1);
		char *buf=ZSTR_VAL(myKey);

        /* Try FPM pool name first, fall back to effective UID */
        size_t plen = samer_get_pool_prefix(buf);
        if (plen == 0) {
            int written = snprintf(buf, myKeyCap, "%lu_", (unsigned long)geteuid());
            if (written > 0 && (size_t)written < myKeyCap) {
                plen = (size_t)written;
            }
        }
		if(plen == 0) {
			disabled = 1;
			zend_string_release(myKey);
			myKey = NULL;
			return orig_key;
		}
        buf[plen] = '\0';
        prefix_len = plen;
    }
    if (orig_key == myKey) return orig_key; // just in case

    size_t orig_len = ZSTR_LEN(orig_key);
    size_t total_len = prefix_len + orig_len;

    /* Key too long? */
    if (total_len >= myKeyCap) {
		size_t new_capacity = total_len + 256;
		ZSTR_LEN(myKey) = myKeyCap; // in case zend_string_realloc use len
		zend_string *s = zend_string_realloc(myKey, new_capacity, 1);
		if (s == NULL) return orig_key; // why would this fail?
		myKey = s;
		myKeyCap = new_capacity;
    }

    /* Copy user key into buffer right after the static prefix */
    memcpy(ZSTR_VAL(myKey) + prefix_len, ZSTR_VAL(orig_key), orig_len);
    ZSTR_VAL(myKey)[total_len] = '\0';
    ZSTR_LEN(myKey) = total_len;
    /* Reset hash so APCu recalculates it for the new combined string */
    myKey->h = 0;
    return myKey;
}

#endif /* SAMER_H */
