# Architecture

This document provides an overview of the php-epub architecture.

## Overview

The library follows a layered architecture with clear separation of concerns:

```
┌─────────────────────────────────────────────┐
│              EpubFile (Facade)              │
│         (Main entry point for users)        │
└─────────────────┬───────────────────────────┘
                  │
        ┌─────────┼─────────┐
        ▼         ▼         ▼
   ┌─────────┐ ┌───────┐ ┌──────────────┐
   │Metadata │ │Spine  │ │ContentManager│
   └─────────┘ └───────┘ └──────────────┘
        │         │              │
        └─────────┼──────────────┘
                  ▼
         ┌─────────────────┐
         │ Parser          │
         │ (EPUB parsing)  │
         └────────┬────────┘
                  │
      ┌───────────┼───────────┐
      ▼           ▼           ▼
┌─────────┐ ┌─────────┐ ┌──────────┐
│ZipHandler│ │XmlParser│ │FileSystem │
└─────────┘ └─────────┘ └──────────┘
```

## Core Components

### EpubFile
The main facade class that coordinates all operations:
- Loads EPUB files (extracts ZIP, parses XML)
- Manages metadata, spine, and content
- Saves modified EPUB files

### Metadata
Handles Dublin Core metadata using traits for each metadata field:
- Title, Authors, Publisher, Language
- Date, Description, Subject, Identifier

### ContentManager
Manages content files within the EPUB:
- Add, update, delete content files
- List content files

### Parser
Validates and parses EPUB structure:
- Validates mimetype file
- Parses container.xml to find OPF file location
- Validates OPF file structure

### Spine
Represents the reading order of EPUB content:
- Manages spine items and their order

## Converters

The library supports multiple PDF conversion backends:

- **TCPDFAdapter**: Uses TCPDF library
- **DompdfAdapter**: Uses Dompdf library  
- **CalibreAdapter**: Uses Calibre command-line tool

## Design Patterns

1. **Facade**: EpubFile provides simple interface
2. **Trait-based composition**: Metadata uses traits for each field
3. **Adapter**: ConverterInterface for multiple backends
4. **Dependency Injection**: Core classes accept dependencies in constructors

## Testing

- PHPUnit for unit testing
- Mock objects for external dependencies
- Fixtures in `tests/fixtures/`
