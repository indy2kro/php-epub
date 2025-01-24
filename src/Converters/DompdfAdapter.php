<?php

declare(strict_types=1);

namespace PhpEpub\Converters;

use Dompdf\Dompdf;
use Dompdf\Options;
use PhpEpub\Exception;

class DompdfAdapter implements ConverterInterface
{
    /**
     * @var array<string, mixed>
     */
    private array $styles;

    /**
     * DompdfAdapter constructor.
     *
     * @param array<string, mixed> $styles Optional styling parameters.
     */
    public function __construct(array $styles = [])
    {
        // Default styling parameters
        $defaultStyles = [
            'font' => 'Arial',
            'font_size' => 12,
            'paper_size' => 'A4',
            'orientation' => 'portrait',
        ];

        // Merge default styles with user-provided styles
        $this->styles = array_merge($defaultStyles, $styles);
    }

    /**
     * Converts the EPUB content to a PDF using Dompdf.
     *
     * @param string $epubDirectory The directory containing the extracted EPUB contents.
     * @param string $outputPath The path where the converted PDF should be saved.
     *
     * @throws Exception If the conversion fails.
     */
    public function convert(string $epubDirectory, string $outputPath): void
    {
        // Initialize Dompdf with options
        $options = new Options();
        $options->set('defaultFont', $this->styles['font']);
        $dompdf = new Dompdf($options);

        // Load EPUB content (this is a simplified example)
        $content = $this->loadEpubContent($epubDirectory);

        // Load HTML content into Dompdf
        $dompdf->loadHtml($content);

        $paperSize = 'A4';
        if (isset($this->styles['paper_size']) && is_string($this->styles['paper_size'])) {
            $paperSize = $this->styles['paper_size'];
        }

        $orientation = 'portrait';
        if (isset($this->styles['orientation']) && is_string($this->styles['orientation'])) {
            $orientation = $this->styles['orientation'];
        }

        // Set paper size and orientation
        $dompdf->setPaper($paperSize, $orientation);

        // Render the PDF
        $dompdf->render();

        // Output the generated PDF to a file
        file_put_contents($outputPath, $dompdf->output());
    }

    /**
     * Loads the EPUB content for conversion.
     *
     * @throws Exception If the content cannot be loaded.
     */
    private function loadEpubContent(string $epubDirectory): string
    {
        // Simplified example: Load content from a specific file
        $contentFile = $epubDirectory . '/content.xhtml'; // Adjust this path as needed
        if (! file_exists($contentFile)) {
            throw new Exception("Content file not found: {$contentFile}");
        }

        $content = file_get_contents($contentFile);
        if ($content === false) {
            throw new Exception("Failed to read content from: {$contentFile}");
        }

        return $content;
    }
}
