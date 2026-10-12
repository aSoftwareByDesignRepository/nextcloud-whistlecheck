<?php

declare(strict_types=1);

namespace OCA\WhistleCheck\Tests\Unit\Service;

use OCA\WhistleCheck\Service\SanitizeService;
use PHPUnit\Framework\TestCase;

final class SanitizeServiceTest extends TestCase
{
	private SanitizeService $svc;

	protected function setUp(): void
	{
		$this->svc = new SanitizeService();
	}

	public function testPlainTextKeepsNewlinesAndTabs(): void
	{
		$out = $this->svc->plainText("line1\nline2\tindented");
		$this->assertSame("line1\nline2\tindented", $out);
	}

	public function testPlainTextStripsNullAndControls(): void
	{
		$out = $this->svc->plainText("a\0b\x01c\x7Fd");
		$this->assertSame('abcd', $out);
	}

	public function testPlainTextStripsBidiOverrides(): void
	{
		// RLO U+202E used for filename extension spoofing
		$out = $this->svc->plainText("normal\u{202E}trojan\u{202C}text");
		$this->assertSame('normaltrojantext', $out);
	}

	public function testPlainTextStripsZeroWidth(): void
	{
		$out = $this->svc->plainText("a\u{200B}b\u{FEFF}c");
		$this->assertSame('abc', $out);
	}

	public function testPlainTextNormalizesCrlf(): void
	{
		$out = $this->svc->plainText("a\r\nb\rc");
		$this->assertSame("a\nb\nc", $out);
	}

	public function testPlainTextCollapsesBlankRuns(): void
	{
		$out = $this->svc->plainText("a\n\n\n\n\nb");
		$this->assertSame("a\n\nb", $out);
	}

	public function testPlainTextTruncatesAtMax(): void
	{
		$out = $this->svc->plainText(str_repeat('x', SanitizeService::MAX_TEXT + 10));
		$this->assertSame(SanitizeService::MAX_TEXT, mb_strlen($out));
	}

	public function testPlainTextRespectsCustomMax(): void
	{
		$this->assertSame('abc', $this->svc->plainText('abcdef', 3));
	}

	public function testEscapeForDisplay(): void
	{
		$this->assertSame('&lt;script&gt;&quot;x&quot;&#039;y&#039;', $this->svc->escapeForDisplay('<script>"x"\'y\''));
		$this->assertSame('&amp;', $this->svc->escapeForDisplay('&'));
	}

	public function testFilenameStripsPathTraversal(): void
	{
		$this->assertSame('evil.txt', $this->svc->filename('../../etc/evil.txt'));
		$this->assertSame('evil.txt', $this->svc->filename('..\\..\\evil.txt'));
	}

	public function testFilenameStripsControlsAndShellChars(): void
	{
		$out = $this->svc->filename('name";rm -rf\x00.pdf');
		$this->assertStringNotContainsString('"', $out);
		$this->assertStringNotContainsString(';', $out);
		$this->assertStringNotContainsString("\0", $out);
	}

	public function testFilenameDotOnlyFallsBack(): void
	{
		$this->assertSame('attachment', $this->svc->filename('..'));
		$this->assertSame('attachment', $this->svc->filename(''));
		$this->assertSame('attachment', $this->svc->filename('   '));
	}

	public function testFilenameKeepsUnicode(): void
	{
		$this->assertSame('Beweis-Übermittlung (2).pdf', $this->svc->filename('Beweis-Übermittlung (2).pdf'));
	}
}
