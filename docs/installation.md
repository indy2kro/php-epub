# Installation

Use Composer to install the PHP EPUB Processor library by running the following command:

```
composer require indy2kro/php-epub
```

## Optional: PDF conversion

PDF conversion uses one of two optional libraries:

```
composer require dompdf/dompdf
composer require tecnickcom/tcpdf
```

### TCPDF core fonts

TCPDF 7 needs the 14 standard PDF core fonts (Helvetica, Times, Courier, …) as JSON definitions, which Composer packages do not ship, and `TCPDFAdapter` uses DejaVu Sans (regular, bold, italic and bold italic) by default, which covers Latin, Greek, Cyrillic, Hebrew and Arabic. Generate them once after installing TCPDF (and again after updating `tecnickcom/tc-lib-pdf-font`, or php-epub, when you installed TCPDF before DejaVu Sans was added; fonts already generated are skipped):

```
php vendor/indy2kro/php-epub/scripts/generate-core-fonts.php
```

The script downloads the Adobe Core14 AFM files and the DejaVu Sans TTF files over HTTPS (certificates verified), checks each file against a pinned SHA-256 hash, and writes the definitions into `vendor/tecnickcom/tc-lib-pdf-font/target/fonts/`, where TCPDF finds them automatically. It exits without doing anything when TCPDF is not installed.

You can make this automatic in your own `composer.json`:

```json
"scripts": {
    "post-install-cmd": ["@php vendor/indy2kro/php-epub/scripts/generate-core-fonts.php"],
    "post-update-cmd": ["@php vendor/indy2kro/php-epub/scripts/generate-core-fonts.php"]
}
```
