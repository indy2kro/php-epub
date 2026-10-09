<?php

declare(strict_types=1);

namespace PhpEpub\Test\Cleanup;

use PhpEpub\Cleanup\ReferenceGraph;
use PhpEpub\EpubFile;
use PhpEpub\Test\Support\CleanupBook;
use PhpEpub\Test\Support\EpubBuilder;
use PhpEpub\Util\FileSystemHelper;
use PHPUnit\Framework\TestCase;

final class ReferenceGraphTest extends TestCase
{
    private string $tmpDir;

    private ?EpubFile $book = null;

    protected function setUp(): void
    {
        $this->tmpDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'graph';
        if (! is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        $this->book?->cleanup();
        (new FileSystemHelper())->deleteDirectory($this->tmpDir);
    }

    private function graph(EpubBuilder $builder): ReferenceGraph
    {
        $this->book = CleanupBook::open($builder, $this->tmpDir);

        return ReferenceGraph::forBook($this->book);
    }

    public function testFollowsStylesheetsImportsFontsAndImages(): void
    {
        $css = '@import "extra.css"; @font-face { font-family: F; src: url(\'../fonts/a.woff2?v=1#x\') format("woff2"); } '
            . 'body { background: url( img/bg.png ) } .x { background: image-set("img/hi.png" 2x) }';
        $builder = CleanupBook::builder(
            <<<XML
<item id="css" href="css/main.css" media-type="text/css"/>
<item id="extra" href="css/extra.css" media-type="text/css"/>
<item id="font" href="fonts/a.woff2" media-type="font/woff2"/>
<item id="bg" href="css/img/bg.png" media-type="image/png"/>
<item id="hi" href="css/img/hi.png" media-type="image/png"/>
<item id="orphan" href="orphan.png" media-type="image/png"/>
<item id="orphanfont" href="fonts/b.woff2" media-type="font/woff2"/>
XML,
            [
                'EPUB/css/main.css' => $css,
                'EPUB/css/extra.css' => 'p { color: red }',
                'EPUB/fonts/a.woff2' => 'font',
                'EPUB/fonts/b.woff2' => 'font',
                'EPUB/css/img/bg.png' => 'png',
                'EPUB/css/img/hi.png' => 'png',
                'EPUB/orphan.png' => 'png',
            ],
            chapterBody: '<p>Text.</p>'
        )->withFile('EPUB/chapter.xhtml', EpubBuilder::xhtml('Chapter', '<p>Text.</p>', 'css/main.css'));

        $analysis = $this->graph($builder)->analyze();

        $this->assertSame(['EPUB/fonts/b.woff2', 'EPUB/orphan.png'], $analysis->unreachable);
        $this->assertTrue($analysis->isReachable('EPUB/fonts/a.woff2'));
        $this->assertTrue($analysis->isReachable('EPUB/css/extra.css'));
        $this->assertTrue($analysis->isReachable('EPUB/css/img/hi.png'));
        $this->assertTrue($analysis->isReachable('EPUB/nav.xhtml'));
        $this->assertSame([], $analysis->unparsable);
    }

    public function testFollowsSrcsetPosterAndStyleAttributes(): void
    {
        $body = '<img src="a.png" srcset="a-1x.png 1x, a-2x.png 2x" alt=""/><video poster="poster.jpg" src="m.mp4"/>'
            . '<div style="background: url(bgs.png)">x</div><object data="obj.svg"/>';
        $items = '';
        $files = [];
        foreach (['a.png' => 'image/png', 'a-1x.png' => 'image/png', 'a-2x.png' => 'image/png', 'poster.jpg' => 'image/jpeg', 'm.mp4' => 'video/mp4', 'bgs.png' => 'image/png', 'obj.svg' => 'image/svg+xml', 'unused.png' => 'image/png'] as $name => $type) {
            $items .= '<item id="' . md5($name) . '" href="' . $name . '" media-type="' . $type . '"/>';
            $files['EPUB/' . $name] = 'data';
        }

        $analysis = $this->graph(CleanupBook::builder($items, $files, chapterBody: $body))->analyze();

        $this->assertSame(['EPUB/unused.png'], $analysis->unreachable);
    }

    public function testFollowsSvgXlinkReferencesTransitively(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><image xlink:href="deep.png"/><use xlink:href="defs.svg#shape"/></svg>';
        $builder = CleanupBook::builder(
            <<<XML
<item id="pic" href="pic.svg" media-type="image/svg+xml"/>
<item id="deep" href="deep.png" media-type="image/png"/>
<item id="defs" href="defs.svg" media-type="image/svg+xml"/>
<item id="nope" href="nope.png" media-type="image/png"/>
XML,
            [
                'EPUB/pic.svg' => $svg,
                'EPUB/defs.svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>',
                'EPUB/deep.png' => 'png',
                'EPUB/nope.png' => 'png',
            ],
            chapterBody: '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><image xlink:href="pic.svg"/></svg>'
        );

        $analysis = $this->graph($builder)->analyze();

        $this->assertSame(['EPUB/nope.png'], $analysis->unreachable);
        $this->assertSame(['EPUB/deep.png', 'EPUB/defs.svg'], $analysis->references['EPUB/pic.svg']);
    }

    public function testFragmentsQueriesAndPercentEncodedHrefsResolve(): void
    {
        $builder = CleanupBook::builder(
            <<<XML
<item id="second" href="chapter%202.xhtml" media-type="application/xhtml+xml"/>
<item id="img" href="my%20image.png" media-type="image/png"/>
XML,
            [
                'EPUB/chapter 2.xhtml' => EpubBuilder::xhtml('Two', '<p><a href="#top">self</a> <img src="my%20image.png?x=1" alt=""/></p>'),
                'EPUB/my image.png' => 'png',
            ],
            chapterBody: '<p><a href="chapter%202.xhtml#frag">two</a></p>'
        );

        $analysis = $this->graph($builder)->analyze();

        $this->assertSame([], $analysis->unreachable);
        $this->assertSame(['EPUB/chapter 2.xhtml'], $analysis->references['EPUB/chapter.xhtml']);
    }

    public function testMalformedDocumentsKeepEverythingTheyMightReference(): void
    {
        $builder = CleanupBook::builder(
            <<<XML
<item id="broken" href="broken.xhtml" media-type="application/xhtml+xml"/>
<item id="mentioned" href="img/mentioned.png" media-type="image/png"/>
<item id="script" href="app.js" media-type="text/javascript"/>
<item id="loaded" href="loaded.png" media-type="image/png"/>
<item id="orphan" href="orphan.png" media-type="image/png"/>
XML,
            [
                'EPUB/broken.xhtml' => '<html><body><img src="img/mentioned.png"><p>unclosed <script src="app.js">',
                'EPUB/app.js' => 'var x = "loaded.png"; !!(((',
                'EPUB/img/mentioned.png' => 'png',
                'EPUB/loaded.png' => 'png',
                'EPUB/orphan.png' => 'png',
            ],
            '<itemref idref="broken"/>'
        );

        $analysis = $this->graph($builder)->analyze();

        $this->assertSame(['EPUB/broken.xhtml'], $analysis->unparsable);
        $this->assertSame(['EPUB/orphan.png'], $analysis->unreachable);
    }

    public function testBrokenStylesheetsAndForeignReferencesDoNotThrow(): void
    {
        $css = "@import url(; } } url( \"unterminated\n body { background: url(../../../../outside.png) url(https://example.com/x.png) url(//cdn/x.png) url(data:image/png;base64,AAAA) url(#frag) url() }";
        $builder = CleanupBook::builder(
            '<item id="css" href="bad.css" media-type="text/css"/><item id="orphan" href="orphan.png" media-type="image/png"/>',
            ['EPUB/bad.css' => $css, 'EPUB/orphan.png' => 'png'],
            chapterBody: '<img src="https://example.com/a.png" alt=""/><img src="../../escape.png" alt=""/><a href="mailto:a@b.c">m</a>'
        )->withFile('EPUB/chapter.xhtml', EpubBuilder::xhtml('Chapter', '<p>x</p>', 'bad.css'));

        $analysis = $this->graph($builder)->analyze();

        $this->assertSame(['EPUB/orphan.png'], $analysis->unreachable);
    }

    public function testFallbacksAndMediaOverlaysFollowTheirItems(): void
    {
        $builder = CleanupBook::builder(
            <<<XML
<item id="second" href="second.xhtml" media-type="application/xhtml+xml" fallback="fb" media-overlay="mo"/>
<item id="fb" href="fallback.xhtml" media-type="application/xhtml+xml"/>
<item id="mo" href="second.smil" media-type="application/smil+xml"/>
<item id="audio" href="audio.mp3" media-type="audio/mpeg"/>
<item id="other" href="other.smil" media-type="application/smil+xml"/>
XML,
            [
                'EPUB/second.xhtml' => EpubBuilder::xhtml('Two', '<p>two</p>'),
                'EPUB/fallback.xhtml' => EpubBuilder::xhtml('FB', '<p>fb</p>'),
                'EPUB/second.smil' => '<smil xmlns="http://www.w3.org/ns/SMIL"><body><par><text src="second.xhtml#p1"/><audio src="audio.mp3"/></par></body></smil>',
                'EPUB/audio.mp3' => 'mp3',
                'EPUB/other.smil' => '<smil xmlns="http://www.w3.org/ns/SMIL"/>',
            ],
            '<itemref idref="second"/>'
        );

        $analysis = $this->graph($builder)->analyze();

        $this->assertSame(['EPUB/other.smil'], $analysis->unreachable);
    }

    public function testAnalyzeFromAnyRootAndReferencesOf(): void
    {
        $builder = CleanupBook::builder(
            '<item id="second" href="second.xhtml" media-type="application/xhtml+xml"/><item id="a" href="a.png" media-type="image/png"/><item id="b" href="b.png" media-type="image/png"/>',
            [
                'EPUB/second.xhtml' => EpubBuilder::xhtml('Two', '<img src="b.png" alt=""/>'),
                'EPUB/a.png' => 'png',
                'EPUB/b.png' => 'png',
            ],
            '<itemref idref="second"/>',
            '<img src="a.png" alt=""/>'
        );
        $graph = $this->graph($builder);

        $analysis = $graph->analyzeFrom(['EPUB/second.xhtml', 'EPUB/missing.xhtml']);

        $this->assertSame(['EPUB/b.png', 'EPUB/second.xhtml'], $analysis->reachable);
        $this->assertSame(['EPUB/a.png', 'EPUB/chapter.xhtml', 'EPUB/nav.xhtml'], $analysis->unreachable);
        $this->assertSame(['EPUB/a.png'], $graph->referencesOf('EPUB/chapter.xhtml'));
        $this->assertSame([], $graph->referencesOf('EPUB/missing.xhtml'));
        $this->assertContains('EPUB/second.xhtml', $graph->defaultRoots());
    }

    public function testFilesReferencedButNotInTheManifestAreReported(): void
    {
        $builder = CleanupBook::builder('', ['EPUB/loose.png' => 'png', 'EPUB/unrelated.png' => 'png'], chapterBody: '<img src="loose.png" alt=""/>');

        $analysis = $this->graph($builder)->analyze();

        $this->assertSame(['EPUB/loose.png'], $analysis->unmanifested);
    }

    public function testXmlStylesheetInstructionsMathmlAndSvgPaintReferencesAreFollowed(): void
    {
        $svg = '<?xml version="1.0"?><?xml-stylesheet href="svg.css" type="text/css"?>'
            . '<svg xmlns="http://www.w3.org/2000/svg"><rect fill="url(defs.svg#grad)" mask="url(&quot;mask.svg#m&quot;)" style="filter: url(filter.svg#f)"/></svg>';
        $builder = CleanupBook::builder(
            <<<XML
<item id="pic" href="pic.svg" media-type="image/svg+xml"/>
<item id="css" href="svg.css" media-type="text/css"/>
<item id="defs" href="defs.svg" media-type="image/svg+xml"/>
<item id="mask" href="mask.svg" media-type="image/svg+xml"/>
<item id="filter" href="filter.svg" media-type="image/svg+xml"/>
<item id="math" href="math.png" media-type="image/png"/>
<item id="orphan" href="orphan.png" media-type="image/png"/>
XML,
            [
                'EPUB/pic.svg' => $svg,
                'EPUB/svg.css' => 'rect { stroke: red }',
                'EPUB/defs.svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>',
                'EPUB/mask.svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>',
                'EPUB/filter.svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>',
                'EPUB/math.png' => 'png',
                'EPUB/orphan.png' => 'png',
            ],
            chapterBody: '<img src="pic.svg" alt=""/><math xmlns="http://www.w3.org/1998/Math/MathML" altimg="math.png"><mi>x</mi></math>'
        );

        $analysis = $this->graph($builder)->analyze();

        $this->assertSame(['EPUB/orphan.png'], $analysis->unreachable);
    }

    public function testDocumentsWithAnXmlStylesheetProcessingInstructionKeepTheirStylesheet(): void
    {
        $xhtml = '<?xml version="1.0"?><?xml-stylesheet href="s.css" type="text/css"?><html xmlns="http://www.w3.org/1999/xhtml"><head><title>t</title></head><body><p>x</p></body></html>';
        $builder = CleanupBook::builder(
            '<item id="css" href="s.css" media-type="text/css"/><item id="orphan" href="orphan.css" media-type="text/css"/>',
            ['EPUB/s.css' => 'p{}', 'EPUB/orphan.css' => 'p{}']
        )->withFile('EPUB/chapter.xhtml', $xhtml);

        $this->assertSame(['EPUB/orphan.css'], $this->graph($builder)->analyze()->unreachable);
    }

    public function testPackageDocumentReferencesAreRoots(): void
    {
        $builder = CleanupBook::builder(
            <<<XML
<item id="map" href="pagemap.xml" media-type="application/oebps-page-map+xml"/>
<item id="handler" href="handler.xhtml" media-type="application/xhtml+xml"/>
<item id="record" href="record.xml" media-type="application/xml"/>
<item id="orphan" href="orphan.png" media-type="image/png"/>
XML,
            ['EPUB/pagemap.xml' => '<page-map/>', 'EPUB/handler.xhtml' => EpubBuilder::xhtml('H', '<p>h</p>'), 'EPUB/record.xml' => '<r/>', 'EPUB/orphan.png' => 'png'],
            metadata: '<link rel="record" href="record.xml" media-type="application/xml"/>'
        );
        $opf = (string) $builder->getFile('EPUB/package.opf');
        $opf = str_replace('<spine>', '<spine page-map="map">', $opf);
        $opf = str_replace('</package>', '<bindings><mediaType media-type="application/x-demo" handler="handler"/></bindings></package>', $opf);
        $builder->withFile('EPUB/package.opf', $opf);

        $analysis = $this->graph($builder)->analyze();

        $this->assertSame(['EPUB/orphan.png'], $analysis->unreachable);
    }

    public function testCssEscapesAreDecodedAndRememberedAsMentioned(): void
    {
        $css = 'a { background: url(a\(b.png) } b { background: url("\63 over.png") } i { background: url(plain.png) }';
        $builder = CleanupBook::builder(
            '<item id="css" href="s.css" media-type="text/css"/><item id="ab" href="a(b.png" media-type="image/png"/><item id="cover" href="cover.png" media-type="image/png"/><item id="plain" href="plain.png" media-type="image/png"/><item id="js" href="app.js" media-type="text/javascript"/><item id="scripted" href="scripted.png" media-type="image/png"/>',
            ['EPUB/s.css' => $css, 'EPUB/a(b.png' => 'png', 'EPUB/cover.png' => 'png', 'EPUB/plain.png' => 'png', 'EPUB/app.js' => 'load("scripted.png")', 'EPUB/scripted.png' => 'png']
        )->withFile('EPUB/chapter.xhtml', EpubBuilder::xhtml('Chapter', '<script src="app.js"></script>', 's.css'));

        $analysis = $this->graph($builder)->analyze();

        $this->assertSame([], $analysis->unreachable);
        $this->assertSame(['EPUB/a(b.png', 'EPUB/cover.png', 'EPUB/scripted.png'], $analysis->mentioned);
    }
}
