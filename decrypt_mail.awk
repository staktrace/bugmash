#!/usr/bin/awk -f

BEGIN {
    ciphertext = "";
    enc = 0;
}

/BEGIN PGP MESSAGE/ {
    enc = 1;
}

/^/ {
    if (enc == 0) {
        print $0;
    } else {
        ciphertext = ciphertext "\n" $0;
    }
}

/END PGP MESSAGE/ {
    enc = 0;
    print ciphertext | "gpg --decrypt --quiet";
    ciphertext = "";
}
