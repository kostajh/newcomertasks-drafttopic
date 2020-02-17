<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Export extends Command {

	protected function configure() {
		$this->setName( 'export' );
	}

	protected function execute( InputInterface $input, OutputInterface $output ) {
		$pdo = new \PDO( 'mysql:dbname=tasks;host=127.0.0.1', 'root' );
		$articlesBelowThreshold = 0;
		$enwikiThresholds = json_decode(
			file_get_contents( 'assets/thresholds/enwiki.json' ),
			true
		);
		$processedThresholds = [];
		foreach ( $enwikiThresholds as $threshold ) {
			$processedThresholds[$threshold['label']] = $threshold['threshold'];
		}
		foreach ( [ 'cs', 'ko', 'ar', 'vi' ] as $lang ) {
			$query = $pdo->prepare( 'SELECT * FROM task WHERE lang = :lang' );
			$query->bindParam( ':lang', $lang );
			$query->execute();
			$results = $query->fetchAll( \PDO::FETCH_ASSOC );
			$resultsPostProcessed = [];
			foreach ( $results as $result ) {
				$topics = json_decode( $result['topic'], true );
				if ( (int)$result['is_foreignwiki'] === 1 ) {
					$topics = array_filter( $topics, function ( $result, $key ) use ( $processedThresholds ) {
						return $result > $processedThresholds[$key];
					}, ARRAY_FILTER_USE_BOTH );
				}
				$result['topic'] = json_encode( $topics );
				$resultsPostProcessed[] = $result;
			}
			$jsonEncoded = json_encode( $resultsPostProcessed, JSON_PRETTY_PRINT );
			file_put_contents( 'assets/tasks.' . $lang . '.json', $jsonEncoded );
		}

		foreach( [ 'cs', 'ko', 'ar', 'vi' ] as $lang ) {
			$topics = [];
			$query = $pdo->prepare( 'SELECT DISTINCT(topic) FROM task WHERE topic NOT LIKE "[]" AND lang = :lang' );
			$query->bindParam( ':lang', $lang );
			$query->execute();
			$result = $query->fetchAll( \PDO::FETCH_ASSOC );
			foreach ( $result as $topic ) {
				$topics = array_merge( $topics ?? [], json_decode( $topic['topic'], true ) ?? [] );
			}
			$topics = array_keys( $topics );
			sort( $topics );
			file_put_contents( 'assets/topics.' . $lang . '.json',
				json_encode( $topics, JSON_PRETTY_PRINT ) );
		}
	}
}
