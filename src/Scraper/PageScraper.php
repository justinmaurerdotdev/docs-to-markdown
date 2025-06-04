<?php

declare(strict_types=1);

namespace DocsToMarkdown\Scraper;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use DOMDocument;
use League\HTMLToMarkdown\HtmlConverter;

class PageScraper {
	private Client $client;
	private HtmlConverter $markdownConverter;
	private array $visitedUrls = [];
	private string $baseUrl = '';
	private string $baseDomain = '';
	private string $outputDir = '';
	private string $originalUrl = '';

	public function __construct(string $outputDir = 'output') {
		$this->client = new Client();
		$this->markdownConverter = new HtmlConverter([
			'remove_nodes' => 'script style iframe button input select textarea',
			'strip_tags' => true,
		]);
		$this->markdownConverter->getConfig()->setOption('strip_tags', true);
		$this->outputDir = $outputDir;

		// Create output directory if it doesn't exist
		if (!is_dir($this->outputDir)) {
			mkdir($this->outputDir, 0755, true);
		}
	}

	public function crawl(string $startUrl, int $maxPages = 100, bool $childPagesOnly = false): void {
		$this->baseUrl = $this->getBaseUrl($startUrl);
		$this->baseDomain = $this->getBaseDomain($startUrl);
		$this->originalUrl = $startUrl;

		echo "Base URL: " . $this->baseUrl . "\n";
		echo "Base Domain: " . $this->baseDomain . "\n";
		echo "Output Directory: " . $this->outputDir . "\n";
		echo "Maximum Pages to Crawl: " . $maxPages . "\n";
		echo "Child Pages Only: " . ($childPagesOnly ? "Yes" : "No") . "\n";
		$urlsToCrawl = [$startUrl];
		$pageCount = 0;

		while (!empty($urlsToCrawl) && $pageCount < $maxPages) {
			$url = array_shift($urlsToCrawl);

			// Skip if we've already visited this URL
			if (isset($this->visitedUrls[$url])) {
				continue;
			}

			echo "Crawling: $url with ".count($urlsToCrawl)."\n";

			try {
				// Mark as visited before processing to avoid duplicate processing
				$this->visitedUrls[$url] = true;

				// Fetch and process the page
				$response = $this->client->get($url);
				$html = (string) $response->getBody();

				// Convert to markdown and save
				$markdown = $this->convertToMarkdown($html);
				$this->saveToFile($url, $markdown);

				// Extract links and add to crawl queue
				$links = $this->extractLinks($html, $url, $childPagesOnly);
				foreach ($links as $link) {
					if (!isset($this->visitedUrls[$link])) {
						$urlsToCrawl[] = $link;
					}
				}

				$pageCount++;

			} catch (GuzzleException $e) {
				echo "Error crawling URL $url: " . $e->getMessage() . "\n";
			}

			// Add a 1-second delay between requests to avoid hitting rate limits
			sleep(1);
		}

		echo "Crawling completed. Processed $pageCount pages.\n";
		echo "URLs remaining in queue: " . count($urlsToCrawl) . "\n";
		if (count($urlsToCrawl) > 0 && $pageCount >= $maxPages) {
			echo "Note: Maximum page limit ($maxPages) reached. Increase the limit to crawl more pages.\n";
		}
	}

	private function extractLinks(string $html, string $currentUrl, bool $childPagesOnly = false): array {
		$dom = new DOMDocument();

		// Suppress warnings from malformed HTML
		libxml_use_internal_errors(true);
		$dom->loadHTML($html);
		libxml_clear_errors();

		$links = [];
		$anchors = $dom->getElementsByTagName('a');

		echo "Found " . $anchors->length . " links in page\n";
		foreach ($anchors as $anchor) {
			if ($anchor->hasAttribute('href')) {
				$href = $anchor->getAttribute('href');
				echo "Found link: $href\n";
				// Skip empty links, javascript, anchors, etc.
				if (empty($href) || strpos($href, 'javascript:') === 0 || strpos($href, '#') === 0) {
					continue;
				}

				// Convert relative URLs to absolute
				$absoluteUrl = $this->makeAbsoluteUrl($href, $currentUrl);
				echo "Absolute URL: $absoluteUrl\n";

				// Check if the URL is from the same domain
				if ($this->isSameDomain($absoluteUrl)) {
					// If child pages only mode is enabled, check if it's a child page
					if ($childPagesOnly) {
						if ($this->isChildPage($absoluteUrl)) {
							$links[] = $absoluteUrl;
							echo "Added child page: $absoluteUrl\n";
						} else {
							echo "Skipped non-child page: $absoluteUrl\n";
						}
					} else {
						// If child pages only mode is disabled, include all same-domain URLs
						$links[] = $absoluteUrl;
					}
				}
			}
		}

		return $links;
	}

	private function makeAbsoluteUrl(string $href, string $baseUrl): string {
		// Already absolute URL
		if (preg_match('~^https?://~i', $href)) {
			return $href;
		}

		// URL with domain but no protocol
		if (strpos($href, '//') === 0) {
			return 'http:' . $href;
		}

		// Root-relative URL
		if (strpos($href, '/') === 0) {
			return $this->baseUrl . $href;
		}

		// Get directory of current URL
		$dir = dirname($baseUrl);

		// Handle ../ in URLs
		while (strpos($href, '../') === 0) {
			$dir = dirname($dir);
			$href = substr($href, 3);
		}

		// Ensure there's a trailing slash on the directory
		if (substr($dir, -1) !== '/') {
			$dir .= '/';
		}

		return $dir . $href;
	}

	private function isSameDomain(string $url): bool {
		$domain = $this->getBaseDomain($url);
		return $domain === $this->baseDomain;
	}

	private function isChildPage(string $url): bool {
		// Check if the URL starts with the original URL
		// This ensures it's a child page and not just any page on the same domain
		return strpos($url, $this->originalUrl) === 0;
	}

	private function getBaseUrl(string $url): string {
		$parts = parse_url($url);
		return $parts['scheme'] . '://' . $parts['host'];
	}

	private function getBaseDomain(string $url): string {
		$parts = parse_url($url);
		return $parts['host'] ?? '';
	}

	private function saveToFile(string $url, string $content): void {
		// Create a filename from the URL
		$filename = $this->createFilenameFromUrl($url);
		$filepath = $this->outputDir . '/' . $filename;

		file_put_contents($filepath, $content);
		echo "Saved: $filepath\n";
	}

	private function createFilenameFromUrl(string $url): string {
		// Remove protocol and domain
		$filename = preg_replace('~^https?://[^/]+/~i', '', $url);

		// Remove query string and fragment
		$filename = preg_replace('~[?#].*$~', '', $filename);

		// Replace slashes and other invalid characters
		$filename = preg_replace('~[/\\\\:*?"<>|]~', '_', $filename);

		// Add .md extension
		if (empty($filename)) {
			$filename = 'index';
		}

		if (!preg_match('~\.md$~i', $filename)) {
			$filename .= '.md';
		}


		// Ensure filename uniqueness
		$baseFilename   = substr( $filename, 0, - 3 ); // Remove .md
		$counter        = 1;
		$uniqueFilename = $filename;

		while ( file_exists( $this->outputDir . '/' . $uniqueFilename ) ) {
			$uniqueFilename = $baseFilename . '_' . $counter . '.md';
			$counter ++;
		}

		return $uniqueFilename;
	}

	private function convertToMarkdown(string $html): string {
		// The HtmlConverter will handle the HTML parsing and conversion to Markdown
		return $this->markdownConverter->convert($html);
	}

	public function scrape(string $url): string {
		try {
			$response = $this->client->get($url);
			$html = (string) $response->getBody();

			return $this->convertToMarkdown($html);
		} catch (GuzzleException $e) {
			throw new \RuntimeException("Error scraping URL: " . $e->getMessage());
		}
	}
}