# Docs to Markdown Scraper

A website scraper that converts HTML to Markdown, designed to be used as a Composer package.

## Installation

You can install the package via composer:

```bash
composer require justinmaurerdotdev/docs-to-markdown
```

## Usage

### Command Line Interface

After installation, you can use the command-line tool:

```bash
vendor/bin/docs-to-markdown https://example.com
```

#### Options

- `--output-dir`, `-o`: Directory to save markdown files (default: "output")
- `--max-pages`, `-m`: Maximum number of pages to crawl (default: 100)
- `--child-only`, `-c`: Only crawl child pages of the URL

Example with options:

```bash
vendor/bin/docs-to-markdown https://example.com --output-dir=docs --max-pages=50 --child-only
```

### Programmatic Usage

You can also use the scraper in your PHP code:

```php
use DocsToMarkdown\Scraper\PageScraper;

// Initialize the scraper with an output directory
$scraper = new PageScraper('output');

// Crawl a website
$scraper->crawl('https://example.com', 100, false);

// Or just scrape a single page
$markdown = $scraper->scrape('https://example.com');
echo $markdown;
```

## Features

- Crawls websites and converts HTML to Markdown
- Follows links within the same domain
- Option to only crawl child pages
- Configurable maximum number of pages to crawl
- Handles relative and absolute URLs
- Saves Markdown files with names derived from URLs

## License

MIT