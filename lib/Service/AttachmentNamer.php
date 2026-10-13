<?php

declare(strict_types=1);

namespace OCA\DeckTrello\Service;

use OCA\DeckTrello\Model\ParsedAttachment;

class AttachmentNamer {
	public static function fileName(ParsedAttachment $attachment): string {
		$raw = trim($attachment->name);
		if ($raw === '' || preg_match('#^https?://#i', $raw)) {
			$raw = 'attachment';
		}
		$raw = str_replace(['\\', '/', ':', '*', '?', '"', '<', '>', '|'], '-', $raw);
		$raw = preg_replace('/[\x00-\x1F\x7F]/u', '', $raw) ?? 'attachment';
		$raw = trim($raw, ". \t");
		if ($raw === '' || $raw === '.' || $raw === '..') {
			$raw = 'attachment';
		}

		$extension = '';
		$base = $raw;
		if (preg_match('/^(.*?)(\.[A-Za-z0-9]{1,8})$/', $raw, $matches) === 1) {
			$base = $matches[1] !== '' ? $matches[1] : 'attachment';
			$extension = $matches[2];
		}

		$id = preg_replace('/[^A-Za-z0-9]/', '', $attachment->id) ?? '';
		$id = substr($id, 0, 24);
		if ($id === '') {
			$id = substr(sha1($attachment->url), 0, 12);
		}
		$suffix = '-' . $id;
		$maxBase = 180 - strlen($suffix) - strlen($extension);
		if (mb_strlen($base) > $maxBase) {
			$base = mb_substr($base, 0, max(1, $maxBase));
		}
		$base = rtrim($base, ". \t");
		if ($base === '') {
			$base = 'attachment';
		}

		return $base . $suffix . $extension;
	}
}
