# PHP EPUB Processor

A PHP library for processing EPUB files, including parsing, metadata manipulation, and format conversion. This library provides tools to handle EPUB files efficiently, offering features like validation, content management, and conversion to other formats using various adapters.

## Features

- **EPUB Loading and Saving**: Easily load and save EPUB files.
- **Metadata Management**: Read and update metadata such as title, authors, and language.
- **Content Management**: Add, update, and delete content files within an EPUB.
- **Validation**: Validate the structure and content of EPUB files.
- **Conversion**: Convert EPUB files to PDF and other formats using adapters for TCPDF, Dompdf, and Calibre.

## Prerequisites

Before you begin, ensure you have the following installed on your system:

- **PHP**: Version 8.2 or higher.
- **PHP Extensions**: `ext-xml`, `ext-zip`.
- **Composer**: For managing PHP dependencies.
- **Calibre**: If you plan to use the CalibreAdapter for conversions.
- **Dompdf**: If you plan to use the DompdfAdapter for PDF conversions.
- **TCPDF**: If you plan to use the TCPDFAdapter for PDF conversions.