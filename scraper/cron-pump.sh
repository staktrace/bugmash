#!/usr/bin/env bash

pushd $HOME/scraper >/dev/null 2>&1
for i in $(grep -l "Prepared statement needs to be re-prepared" *.err 2>/dev/null); do
    ./pump.sh ${i%.err}
    rm $i ${i%.err}
done
for i in $(grep -l "Can't connect to MySQL server on 'db.staktrace.com'" *.err 2>/dev/null); do
    ./pump.sh ${i%.err}
    rm $i ${i%.err}
done
for i in $(grep -l "Lost connection to MySQL server at 'reading authorization packet'" *.err 2>/dev/null); do
    ./pump.sh ${i%.err}
    rm $i ${i%.err}
done
for i in $(grep -l "MySQL server has gone away" *.err 2>/dev/null); do
    ./pump.sh ${i%.err}
    rm $i ${i%.err}
done
for i in $(grep -l "Error connecting to db:" *.err 2>/dev/null); do
    ./pump.sh ${i%.err}
    rm $i ${i%.err}
done
for i in $(grep -l "into DB" *.err 2>/dev/null); do
    ./pump.sh ${i%.err}
    rm $i ${i%.err}
done
popd >/dev/null 2>&1
