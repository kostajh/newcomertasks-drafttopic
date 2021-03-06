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
		$this->addOption( 'overwrite', null, InputOption::VALUE_OPTIONAL, '', false );
		$this->addOption( 'limit', null, InputOption::VALUE_OPTIONAL, '', 25 );
		$this->addOption( 'groupname', null, InputOption::VALUE_OPTIONAL, '', '' );
		$this->addOption( 'template-source', null, InputOption::VALUE_OPTIONAL, '', 'Growth/Personalized_first_day/Newcomer_tasks/Prototype/templates' );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {

		$lang = $input->getOption( 'lang' );
		$templateGroupName = $input->getOption( 'groupname' );
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
			if ( $templateGroupName  && $groupName !== $templateGroupName ) {
				continue;
			}
			$output->writeln( sprintf( '<info>Analyzing %s</info>', $groupName ) );
			foreach ( $group['templates'] as $template ) {
				$output->writeln( '<info>' . $template . '</info>' );
				$limit = $input->getOption( 'limit' );
				$queryParams = [
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
					]
				];
				$items = $this->executeQuery( $client, $baseUri, $queryParams, 0 );
				$output->writeln(
					sprintf( '<info>Processing %s items...</info>', count( $items ) )
				);
				foreach ( $items as $item ) {
					if ( !$input->getOption( 'overwrite' ) &&
						 $this->recordExists(
							 $item,
							 $lang,
							 $template
						 ) ) {
						continue;
					}
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
								'[]',
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
								'[]',
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
							'[]',
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
					$topics = $this->extractTopics( $oresResponse['enwiki']['scores'][$revId]['drafttopic']['score'] ?? [] );
					$this->writeDb(
						$title,
						$enwiki_title,
						$category_derived,
						$revId,
						$wikibaseItem,
						$topics,
						$template,
						$lang
					);
					$output->writeln( sprintf( '<info>%s (%s): %s</info>', $title, $enwiki_title,
						$topics ) );
				}
			}
		}

	}

	private function extractTopics( array $predictions ) {
		$probability = $predictions['probability'];
		arsort( $probability );
		$top_results = array_slice( $probability, 0, 3 );
		array_filter( $top_results, function ( $result ) {
			return $result > 0.05;
		} );
		return json_encode( $top_results );
	}

	protected function executeQuery( Client $client, $baseUri, array $params, int $offset = 0,
									 array $results = [] ) {
		$params['query']['gsroffset'] = $offset;
		$response = $client->request( 'GET', $baseUri, $params );
		$decoded = json_decode( $response->getBody()->getContents(), true );
		if ( isset( $decoded['continue']['continue'] ) ) {
			return array_merge( $results, $this->executeQuery(
				$client,
				$baseUri,
				$params,
				$decoded['continue']['gsroffset'],
				$decoded['query']['pages'] ?? []
			) );
		}
		return array_merge( $results, $decoded['query']['pages'] ?? [] );
	}

	private function writeDb(
		$title, $enwiki_title = '', $category_derived = 0, $revId = 0,
		$wikibaseItem = '', $topics = '[]', $template = '', $lang = ''
	) {
		$pdo = new \PDO( 'mysql:dbname=tasks;host=127.0.0.1', 'root' );
		$statement = $pdo->prepare(
			'INSERT INTO ' .
			'task ( page_title, topic, template, enwiki_title, category_derived, rev_id, wikibase_id, lang ) ' .
			'VALUES ( :page_title, :topic, :template, :enwiki_title, :category_derived, :rev_id, :wikibase_id, :lang )'
		);
		$trimmedTitle = substr( $title, 0, 255 );
		$statement->bindParam( ':page_title', $trimmedTitle );
		$trimmedEnwikiTitle = substr( $enwiki_title, 0, 255 );
		$statement->bindParam( ':enwiki_title', $trimmedEnwikiTitle );
		$statement->bindParam( ':category_derived', $category_derived );
		$statement->bindParam( ':wikibase_id', $wikibaseItem );
		$statement->bindParam( ':rev_id', $revId );
		$statement->bindParam( ':topic', $topics );
		$statement->bindParam( ':template', $template );
		$statement->bindParam( ':lang', $lang );
		if ( !$statement->execute() ) {
			var_dump( $statement->errorInfo() );
		}
	}

	private function recordExists( array $item, $lang, $template ) {
		$pdo = new \PDO( 'mysql:dbname=tasks;host=127.0.0.1', 'root' );
		$query = $pdo->prepare( 'SELECT id FROM task WHERE lang = :lang AND page_title = :title AND template = :template' );
		$query->bindParam( ':lang', $lang );
		$query->bindParam( ':template', $template );
		$query->bindParam( ':title', $item['title'] );
		$query->execute();
		if ( !$query->execute() ) {
			var_dump( $query->errorInfo() );
			return false;
		}
		$result = $query->fetch( \PDO::FETCH_ASSOC );
		return isset( $result['id'] ) && $result['id'] > 0;
	}
}
