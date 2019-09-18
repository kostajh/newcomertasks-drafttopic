<?php

namespace App\Command;

use GuzzleHttp\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ProcessTasks extends Command {

	protected function configure() {
		$this->setName( 'process' );
		$this->addOption( 'lang', null, InputOption::VALUE_REQUIRED );
		$this->addOption( 'limit', null, InputOption::VALUE_OPTIONAL, '', 25 );
		$this->addOption( 'template-source', null, InputOption::VALUE_OPTIONAL, '', 'Growth/Personalized_first_day/Newcomer_tasks/Prototype/templates' );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {

		$lang = $input->getOption( 'lang' );
		$baseUri = sprintf( 'https://%s.wikipedia.org/w/api.php', $lang);
		$client = new Client( [
			'base_uri' => $baseUri
		] );
		$templates = json_decode( $client->request( 'GET', 'https://www.mediawiki.org/w/api.php', [
			'query' => [
				'action' => 'query',
				'prop' => 'revisions',
				'titles' => sprintf( '%s/%s.json', $input->getOption( 'template-source' ), $lang ),
				'format' => 'json',
				'formatversion' => 2,
				'rvprop' => 'content',
				'rvslots' => '*'
			]
		] )->getBody()->getContents(), true );
		$templateGroups = json_decode( $templates['query']['pages'][0]['revisions'][0]['slots']['main']['content'], true );
		foreach( $templateGroups as $groupName => $group ) {
			$output->writeln( sprintf( '<info>Analyzing %s</info>', $groupName ) );
			foreach ( $group['templates'] as $template ) {
				$output->writeln( '<info>' . $template . '</info>' );
				$limit = $input->getOption( 'limit' );
				$response = $client->request( 'GET', $baseUri,
					[
						'query' => [
							'action' => 'query',
							'format' => 'json',
							'formatversion' => 2,
							'prop' => 'langlinks|pageprops',
							'list' => '',
							'generator' => 'search',
							'llprop' => '',
							'lllang' => 'en',
							'lllimit' => $limit,
							'gsrsearch' => 'hastemplate:"' . $template . '"',
							'gsrlimit' => $limit,
							'gsrsort' => 'random',
						]
					] );
				$items = json_decode( $response->getBody()->getContents(), true );
				$items = $items[ 'query' ][ 'pages' ] ?? [];
				foreach ( $items as $item ) {
					$category_derived = 0;
					$wikibaseItem = null;
					$enwiki_title = $item['langlinks'][0]['title'] ?? null;
					$title = $item['title'];
					if ( !$enwiki_title ) {
						// Try to get the title from the wikibase ID, if we have it.
						$wikibaseItem = $item['pageprops']['wikibase_item'] ?? null;
						if ( !$wikibaseItem ) {
							$output->writeln( '<error>No langlinks or wikibase item for </error>' .
											  json_encode(
												  $item ) );
							$this->writeDb(
								$title ,
								'',
								0,
								0,
								'',
								'',
								$template,
								$lang
							);
							continue;
						}
						$wikibaseResponse = json_decode( $client->request( 'GET', 'https://www.wikidata.org/w/api.php',
							[ 'query' => [
								'action' => 'wbgetentities',
								'format' => 'json',
								'formatversion' => 2,
								'ids' => $wikibaseItem,
								'sites' => 'enwiki',
								'props' => 'labels',
								'languages' => 'en',
								'normalize' => 1
							] ] )->getBody()->getContents(), true );
						$enwiki_title = $wikibaseResponse['entities'][$wikibaseItem]['labels']['en']['value'] ?? null;
						if ( !$enwiki_title ) {
							$this->writeDb(
								$title,
								$enwiki_title,
								0,
								0,
								$wikibaseItem,
								'',
								$template,
								$lang
							);
							$output->writeln( '<error>Could not find title from wikibase item for</error> ' .
											  json_encode( $item ) );
							continue;
						}
					}
					$enWikiResponse = $client->request( 'GET', 'https://en.wikipedia.org/w/api.php',
						[
							'query' => [
								'action' => 'query',
								'format' => 'json',
								'formatversion' => 2,
								'prop' => 'revisions',
								'titles' => $enwiki_title,
							]
						] );
					$enWikiResponse = json_decode( $enWikiResponse->getBody()->getContents(), true );
					$revId = $enWikiResponse['query']['pages'][0]['revisions'][0]['revid'] ?? 0;
					if ( !$revId ) {
						$this->writeDb(
							$title,
							$enwiki_title,
							0,
							$revId,
							$wikibaseItem,
							'',
							$template,
							$lang
						);
						$output->writeln( '<error>No rev ID found for ' . $title . ' (' . $enwiki_title .
										  ')</error>' );
						continue;
					}
					$oresResponse = json_decode( $client->request( 'GET',
						sprintf( 'https://ores.wikimedia.org/v3/scores/enwiki/%d/drafttopic', $revId )
					)->getBody()->getContents(), true );
					$topic = $oresResponse['enwiki']['scores'][$revId]['drafttopic']['score']['prediction'] ?? [ '' ];
					$topic = current( $topic );
					$this->writeDb(
						$title,
						$enwiki_title,
						$category_derived,
						$revId,
						$wikibaseItem,
						$topic,
						$template,
						$lang
					);
					$output->writeln( sprintf( '<info>%s (%s): %s</info>', $title, $enwiki_title, $topic
					) );
				}
			}
		}

	}

	private function writeDb(
		$title, $enwiki_title = '', $category_derived = 0, $revId = 0,
		$wikibaseItem = '', $topic = '', $template = '', $lang = ''
	) {
		$pdo = new \PDO( 'mysql:dbname=tasks;host=127.0.0.1', 'root' );
		$query = $pdo->prepare( 'SELECT COUNT(*) FROM task WHERE title = :title AND template = :template' );
		$query->bindParam( ':title', $title );
		$query->bindParam( ':template', $template );
		$count = $query->execute();
		if ( $count > 0 ) {
			return;
		}
		$statement = $pdo->prepare(
			'INSERT INTO ' .
			'task ( page_title, topic, template, enwiki_title, category_derived, rev_id, wikibase_id, lang ) ' .
			'VALUES ( :page_title, :topic, :template, :enwiki_title, :category_derived, :rev_id, :wikibase_id, :lang )'
		);
		$trimmedTitle = substr( $title, 0, 63 );
		$statement->bindParam( ':page_title', $trimmedTitle );
		$trimmedEnwikiTitle = substr( $enwiki_title, 0, 63 );
		$statement->bindParam( ':enwiki_title', $trimmedEnwikiTitle );
		$statement->bindParam( ':category_derived', $category_derived );
		$statement->bindParam( ':wikibase_id', $wikibaseItem );
		$statement->bindParam( ':rev_id', $revId );
		$statement->bindParam( ':topic', $topic );
		$statement->bindParam( ':template', $template );
		$statement->bindParam( ':lang', $lang );
		if ( !$statement->execute() ) {
			var_dump( $statement->errorInfo() );
		}
	}
}
