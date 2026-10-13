<?php

declare(strict_types=1);

namespace OCA\DeckTrello\Tests\Unit;

use OCA\DeckTrello\Model\ParsedAttachment;
use OCA\DeckTrello\Model\TrelloCredentials;
use OCA\DeckTrello\Service\AttachmentNamer;
use OCA\DeckTrello\Service\TrelloAttachmentClient;
use OCA\DeckTrello\Service\TrelloBoardClient;
use PHPUnit\Framework\TestCase;

class AttachmentDownloadTest extends TestCase {
	public function testFileNameKeepsExtensionAndTrelloId(): void {
		$name = AttachmentNamer::fileName(new ParsedAttachment(
			'6ab6db7720fac7d95e1dd79b',
			'image.png',
			'https://trello.com/1/cards/abc/attachments/def/download/image.png',
			'image/png',
			100,
			true,
		));

		self::assertSame('image-6ab6db7720fac7d95e1dd79b.png', $name);
	}

	public function testSameDisplayNameGetsDifferentFiles(): void {
		$first = AttachmentNamer::fileName($this->upload('aaa111', 'image.png'));
		$second = AttachmentNamer::fileName($this->upload('bbb222', 'image.png'));

		self::assertNotSame($first, $second);
	}

	public function testDownloadUrlUsesTheApiHost(): void {
		self::assertSame(
			'https://api.trello.com/1/cards/abc/attachments/def/download/image.png',
			TrelloAttachmentClient::apiDownloadUrl('https://trello.com/1/cards/abc/attachments/def/download/image.png'),
		);
	}

	public function testAllowsTrelloDownloadAndItsStorageRedirect(): void {
		self::assertTrue(TrelloAttachmentClient::isAllowedUrl(
			'https://trello.com/1/cards/abc/attachments/def/download/image.png',
			true,
		));
		self::assertTrue(TrelloAttachmentClient::isAllowedUrl(
			'https://trello-attachments.s3.amazonaws.com/abc/def/image.png',
			false,
		));
		self::assertFalse(TrelloAttachmentClient::isAllowedUrl(
			'https://trello-attachments.s3.amazonaws.com/abc/def/image.png',
			true,
		));
		self::assertFalse(TrelloAttachmentClient::isAllowedUrl('http://trello.com/1/cards/abc', true));
		self::assertFalse(TrelloAttachmentClient::isAllowedUrl('https://127.0.0.1/image.png', false));
		self::assertFalse(TrelloAttachmentClient::isAllowedUrl('https://evil.example/image.png', false));
	}

	public function testAcceptsTrelloBoardUrlOrId(): void {
		self::assertSame('abcdefgh', TrelloBoardClient::normalizeBoard('https://trello.com/b/abcdefgh/final-night'));
		self::assertSame('6994ee4654d8cfbc324a9b44', TrelloBoardClient::normalizeBoard('6994ee4654d8cfbc324a9b44'));
	}

	public function testRejectsForeignBoardUrl(): void {
		$this->expectException(\InvalidArgumentException::class);
		TrelloBoardClient::normalizeBoard('https://example.com/b/abcdefgh');
	}

	public function testRejectsMalformedCredentials(): void {
		$this->expectException(\InvalidArgumentException::class);
		new TrelloCredentials('short', 'token-with-dash');
	}

	private function upload(string $id, string $name): ParsedAttachment {
		return new ParsedAttachment(
			$id,
			$name,
			'https://trello.com/1/cards/abc/attachments/' . $id . '/download/' . $name,
			'image/png',
			10,
			true,
		);
	}
}
