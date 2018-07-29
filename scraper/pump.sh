#!/usr/bin/env bash

export SENDER=bugzilla-daemon@mozilla.org
cat $1 | ./decrypt_mail.awk | ./filter.php
