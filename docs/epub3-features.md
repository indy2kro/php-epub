# EPUB 3 Features

Upgrading a book to EPUB 3, landmarks and the page list, fixed layout, media overlays and plain-text extraction.

## Upgrading an EPUB 2 book

```php
use PhpEpub\EpubFile;

$epubFile = EpubFile::open('/path/to/old.epub');

if ($epubFile->upgradeToEpub3()) {
    $epubFile->save('/path/to/new.epub');
}
```

`EpubFile::upgradeToEpub3(): bool` converts a loaded EPUB 2 book in place; `save()` writes the result. It returns `true` when the book was converted and `false`, changing nothing, when it already is EPUB 3. It throws an `Exception` when the book is not loaded or the navigation document cannot be written; the book may be partly converted then, so open it again. The conversion:

- sets the package `version` to `3.0` and adds `dcterms:modified`;
- generates a navigation document (`nav.xhtml` next to the OPF, or `nav-2.xhtml`, ... when that name is taken) from the NCX entries, with the `nav` manifest property. A book without NCX entries gets one entry per linear XHTML document of the reading order, named after its file. The NCX stays (and `spine@toc` with it) for EPUB 2 reading systems;
- turns the `<guide>` into the navigation document's landmarks (see below) and keeps the guide too. Guide types without an EPUB 3 equivalent, such as `other.custom`, are not carried over to the landmarks;
- converts the `opf:*` attributes, which EPUB 3 does not allow on Dublin Core elements: `opf:role` and `opf:file-as` become `role` (scheme `marc:relators`) and `file-as` refinements; the `opf:scheme` of an ISBN or DOI identifier becomes an `identifier-type` refinement (ONIX code list 5: `15` ISBN-13, `02` ISBN-10, `06` DOI; other schemes are dropped); every other `opf:*` attribute is removed;
- keeps one `dc:date`: the publication date (the one with the `publication` event, or else the first without an event). Dates with the `creation`, `issued` and `copyright` events become `dcterms:created`, `dcterms:issued` and `dcterms:dateCopyrighted` properties; the `modification` date is replaced by `dcterms:modified`, and any other date is dropped;
- gives the cover image the `cover-image` property (the `<meta name="cover">` stays for older readers);
- adds the manifest properties XHTML documents need for their content (`svg`, `mathml`, `scripted`, `remote-resources`), as `ContentManager::updateManifestProperties()` does.

Content documents are not rewritten. An XHTML 1.1 document with an old `DOCTYPE` or obsolete elements may still draw EPUBCheck errors in EPUB 3 even though the package is valid; `validate()` does not report those. A book whose metadata declares `xmlns:opf` gets the new refinements as `<opf:meta …>`, which is the same XML in the same namespace.

## Landmarks and the page list

Landmarks are the places a reading system offers as "go to cover", "go to the table of contents" or "go to the beginning": the EPUB 3 navigation document's `landmarks` nav and the EPUB 2 `<guide>`. They are read and written through [TableOfContents](table-of-contents.md) as `PhpEpub\Landmark` objects (`type`, `title`, `path`, `fragment`), with the EPUB 3 `epub:type` as the type:

```php
use PhpEpub\Landmark;

$toc = $epubFile->getTableOfContents();

foreach ($toc->getLandmarks() as $landmark) {
    echo $landmark->type, ': ', $landmark->title, ' -> ', $landmark->path, "\n";
}

$toc->setLandmarks([
    new Landmark('cover', 'Cover', 'EPUB/text/cover.xhtml'),
    new Landmark('toc', 'Contents', 'EPUB/nav.xhtml'),
    new Landmark('bodymatter', 'Start of the book', 'EPUB/text/chapter-1.xhtml'),
]);
$epubFile->save();
```

- `getLandmarks()` returns the landmarks of the navigation document's `landmarks` nav or, when it has none, those of the `<guide>` that have an EPUB 3 equivalent, with the type translated (`text` becomes `bodymatter`, `title-page` becomes `titlepage`, `acknowledgements` becomes `acknowledgments`, `notes` becomes `endnotes`; `Landmark::fromGuideType()` and `Landmark::toGuideType()` expose the mapping). Landmarks that point outside the book are left out.
- `setLandmarks()` replaces them. They are written to the navigation document's `landmarks` nav, which is created when missing (hidden, after the other navs), and, for an EPUB 2 book or an EPUB 3 book that keeps a `<guide>`, to the guide as well, after the spine. Landmarks whose type the guide cannot express (for example `chapter`) stay out of the guide. `[]` removes the nav and the guide. It throws an `Exception` when a landmark has an empty type, title or path, a value is not valid XML text, a path leaves the book, or the book has neither a navigation document nor a guide.
- `getPageList()` returns the print page numbers of the navigation document's `page-list` nav or, when it has none, of the NCX `pageList`, as a list of `TocEntry` (title = the page label); `[]` when the book has neither. It is read-only.

The guide itself is available through `Manifest::getGuideReferences()` and `Manifest::setGuideReferences()`, which use the guide's own types.

## Fixed layout and rendition

The rendition vocabulary tells a reading system whether a book is reflowable or fixed layout. It is EPUB 3 only: the setters throw an `Exception` for an EPUB 2 package, and the getters return `null` there.

```php
$metadata = $epubFile->getMetadata();
$metadata->setRenditionLayout('pre-paginated');   // or 'reflowable'
$metadata->setRenditionOrientation('portrait');   // auto, landscape, portrait
$metadata->setRenditionSpread('none');            // none, auto, landscape, portrait, both
$metadata->setRenditionFlow('paginated');         // auto, paginated, scrolled-continuous, scrolled-doc

$spine = $epubFile->getSpine();
$spine->setPageSpread('page-1', 'right');         // left, right or center; null removes it
$spine->setItemRendition('page-2', 'layout', 'reflowable');   // override one page; null removes it
$spine->setItemProperties('page-3', ['page-spread-left', 'rendition:orientation-landscape']);
```

- `Metadata::getRenditionLayout()` / `setRenditionLayout()` and the matching `…Orientation()`, `…Spread()` and `…Flow()` methods read and write the `rendition:*` metadata. A value outside the vocabulary throws an `Exception`; `null` removes the property.
- `Spine::getItemProperties($idref)` / `setItemProperties($idref, array $properties)` read and replace the raw `properties` of an itemref (tokens without white space). `getPageSpread()` / `setPageSpread()` handle `page-spread-left`, `page-spread-right` and `rendition:page-spread-center`; `getItemRendition($idref, $aspect)` / `setItemRendition($idref, $aspect, $value)` handle the per-item overrides (`rendition:layout-pre-paginated`, `rendition:orientation-landscape`, ...). Other properties of the entry are kept, and the item must be in the spine.
- Writing any `rendition:` metadata or property declares the prefix in the package element, `prefix="rendition: http://www.idpf.org/vocab/rendition/#"`, unless the package declares it already. The prefix is reserved from EPUB 3.1 on, but a package of version `3.0` (which includes EPUB 3.2 and 3.3 books) and older reading systems expect the declaration, and declaring a reserved prefix with its standard URI is allowed.

## Media overlays

A media overlay is a SMIL document that narrates a content document. Overlays are EPUB 3 only.

```php
$manifest = $epubFile->getManifest();
$manifest->setMediaOverlay('chapter-1', 'chapter-1-smil');   // content item id, SMIL item id
echo $manifest->getMediaOverlay('chapter-1');                // 'chapter-1-smil'

$metadata = $epubFile->getMetadata();
$metadata->setMediaDuration('0:32:29');                      // the whole narration
$metadata->setMediaDurationOf('chapter-1-smil', '0:16:00.5'); // one overlay
$metadata->setMediaActiveClass('-epub-media-overlay-active');
$metadata->setMediaPlaybackActiveClass('-epub-media-overlay-playing');
$metadata->setMediaNarrators(['Ann Narrator']);
```

- `Manifest::setMediaOverlay($id, $overlayId)` sets the `media-overlay` attribute of an XHTML or SVG item; `null` removes it. It throws an `Exception` when an item is unknown, the content item is not XHTML or SVG, the overlay is not an `application/smil+xml` item, or the package is EPUB 2. `getMediaOverlay($id)` returns the overlay id or `null`. Deleting the SMIL item clears the reference and its duration.
- `Metadata::getMediaDuration()` / `setMediaDuration()` handle the book's total `media:duration`; `getMediaDurationOf($overlayId)` / `setMediaDurationOf($overlayId, $duration)` the duration of one overlay (a refinement of its SMIL item, which must be in the manifest); `getMediaDurations()` returns them all, keyed by SMIL item id. Durations must be SMIL clock values: `0:32:29.5` (hours, minutes, seconds), `32:29`, or a time count with an optional unit, `1949.5s`, `1500ms`, `45min`, `3h`; anything else throws an `Exception`. The library does not check that the total equals the sum of the overlays.
- `getMediaActiveClass()` / `setMediaActiveClass()` and `getMediaPlaybackActiveClass()` / `setMediaPlaybackActiveClass()` take a CSS class name without white space; `getMediaNarrators()` / `setMediaNarrators()` a list of names.

## Plain text

```php
$text = $epubFile->getContentManager()->getText('EPUB/text/chapter-1.xhtml');   // one document

foreach ($epubFile->getText() as $path => $text) {   // the whole book, in reading order
    echo $path, ': ', str_word_count($text), " words\n";
}
```

- `ContentManager::getText($path)` returns the text of one XHTML or HTML document: one line per paragraph, heading, list item, table row and `<br>`, white space collapsed, entities decoded, with scripts, styles and the document head left out. The document is read in its declared encoding (UTF-8 or UTF-16, as for conversion) and does not have to be well-formed. It throws an `Exception` if the file cannot be read.
- `EpubFile::getText(bool $linearOnly = true)` returns `path => text` for the XHTML and HTML documents of the spine, in reading order. Auxiliary content (`linear="no"`, such as notes) is skipped unless `$linearOnly` is `false`; spine items that are not XHTML or HTML, or whose file is missing, are skipped.
