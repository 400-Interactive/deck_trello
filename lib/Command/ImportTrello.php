<?php

declare(strict_types=1);

namespace OCA\DeckTrello\Command;

use OCA\DeckTrello\Model\TrelloCredentials;
use OCA\DeckTrello\Service\ApiKey;
use OCA\DeckTrello\Service\DeckWriter;
use OCA\DeckTrello\Service\TrelloBoardClient;
use OCA\DeckTrello\Service\TrelloParser;
use OCA\DeckTrello\Service\UserMap;
use OCA\DeckTrello\Service\WarningTranslator;
use OCP\IUserManager;
use OCP\IUserSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ImportTrello extends Command {
	public function __construct(
		private readonly TrelloParser $parser,
		private readonly TrelloBoardClient $boards,
		private readonly DeckWriter $writer,
		private readonly WarningTranslator $translator,
		private readonly ApiKey $apiKey,
		private readonly IUserManager $userManager,
		private readonly IUserSession $userSession,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('deck_trello:import');
		$this->setDescription('Import a Trello board JSON export into a new Deck board');
		$this->addArgument('path', InputArgument::OPTIONAL, 'Path to the Trello JSON export');
		$this->addOption('user', null, InputOption::VALUE_REQUIRED, 'Nextcloud user id that will own the board');
		$this->addOption('map-user', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Map a Trello username to a Nextcloud uid (trelloUsername=nextcloudUid)');
		$this->addOption('map-file', null, InputOption::VALUE_REQUIRED, 'File of trelloUsername=nextcloudUid lines');
		$this->addOption('include-closed-lists', null, InputOption::VALUE_NONE, 'Import lists that are closed in Trello');
		$this->addOption('use-deck-checklist', null, InputOption::VALUE_NONE, 'Store checklists in Deck Checklists when that app is enabled');
		$this->addOption('trello-key', null, InputOption::VALUE_REQUIRED, 'Trello API key used to download attachments');
		$this->addOption('trello-token', null, InputOption::VALUE_REQUIRED, 'Trello API token used to download attachments');
		$this->addOption('trello-board', null, InputOption::VALUE_REQUIRED, 'Trello board URL or id. Imports the full comment history and requires the API key and token');
		$this->addOption('board', null, InputOption::VALUE_REQUIRED, 'Existing Deck board id that should receive the files instead of creating a board');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$pathArgument = $input->getArgument('path');
		$path = is_string($pathArgument) ? $pathArgument : '';
		$trelloBoard = trim((string)($input->getOption('trello-board') ?? ''));
		$userId = (string)$input->getOption('user');
		if ($userId === '') {
			$output->writeln('<error>Option --user is required.</error>');
			return 1;
		}
		$user = $this->userManager->get($userId);
		if ($user === null) {
			$output->writeln('<error>Nextcloud user "' . $userId . '" was not found.</error>');
			return 1;
		}
		try {
			$userMap = UserMap::fromLines($input->getOption('map-user'));
			$mapFile = $input->getOption('map-file');
			if (is_string($mapFile) && $mapFile !== '') {
				$userMap = array_merge(UserMap::fromFile($mapFile), $userMap);
			}
			$credentials = $this->credentials($input);
			$includeClosed = (bool)$input->getOption('include-closed-lists');
			if ($trelloBoard !== '') {
				if ($credentials === null) {
					throw new \InvalidArgumentException('Options --trello-key and --trello-token are required with --trello-board.');
				}
				$fetched = $this->boards->fetch($trelloBoard, $credentials);
				$parsed = $this->parser->parse($fetched['json'], $includeClosed, $fetched['complete']);
			} else {
				if ($path === '' || !is_file($path) || !is_readable($path)) {
					throw new \InvalidArgumentException($path === '' ? 'Choose a JSON file or pass --trello-board.' : 'Cannot read "' . $path . '".');
				}
				$json = file_get_contents($path);
				if ($json === false) {
					throw new \InvalidArgumentException('Cannot read "' . $path . '".');
				}
				$parsed = $this->parser->parse($json, $includeClosed);
			}
		} catch (\InvalidArgumentException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}

		$boardOption = $input->getOption('board');
		$boardId = is_numeric($boardOption) ? (int)$boardOption : 0;
		$this->userSession->setUser($user);
		try {
			$useDeckChecklists = (bool)$input->getOption('use-deck-checklist');
			if ($boardId > 0) {
				if ($credentials === null) {
					$output->writeln('<error>Options --trello-key and --trello-token are required with --board.</error>');
					return 1;
				}
				$result = $this->writer->attachToExisting($parsed, $user->getUID(), $boardId, $credentials, $useDeckChecklists);
			} else {
				$result = $this->writer->import($parsed, $user->getUID(), $userMap, $credentials, $useDeckChecklists);
			}
		} catch (\InvalidArgumentException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		} catch (\RuntimeException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}

		if ($boardId > 0) {
			$output->writeln('Added files to board "' . $result->boardTitle . '" (id ' . $result->boardId . ').');
		} else {
			$output->writeln('Board "' . $result->boardTitle . '" created with id ' . $result->boardId . '.');
		}
		$output->writeln('Lists: ' . $result->stacks);
		$output->writeln('Labels: ' . $result->labels);
		$output->writeln('Cards: ' . $result->cards);
		$output->writeln('Archived cards: ' . $result->archivedCards);
		$output->writeln('Comments: ' . $result->comments);
		$output->writeln('Assignments: ' . $result->assignedUsers);
		$output->writeln('Attachments: ' . $result->attachmentsImported);
		$output->writeln('Skipped cards: ' . $result->skippedCards);
		foreach ($this->translator->translateAll($result->warnings) as $warning) {
			$output->writeln('<comment>' . $warning . '</comment>');
		}
		return 0;
	}

	private function credentials(InputInterface $input): ?TrelloCredentials {
		$typedKey = trim((string)($input->getOption('trello-key') ?? ''));
		$token = trim((string)($input->getOption('trello-token') ?? ''));
		if ($typedKey === '' && $token === '') {
			return null;
		}
		$key = $this->apiKey->resolve($typedKey);
		if ($key === '' || $token === '') {
			throw new \InvalidArgumentException('Both --trello-key and --trello-token are required to download attachments.');
		}
		return new TrelloCredentials($key, $token);
	}
}
