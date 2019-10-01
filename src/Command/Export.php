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
		$topics = [];
		$pdo = new \PDO( 'mysql:dbname=tasks;host=127.0.0.1', 'root' );
		$query = $pdo->prepare( 'SELECT * FROM task ORDER BY lang' );
		$query->execute();
		$results = $query->fetchAll( \PDO::FETCH_ASSOC );
		$jsonEncoded = json_encode( $results, JSON_PRETTY_PRINT );
		file_put_contents( 'assets/tasks.json', $jsonEncoded );
		$query = $pdo->prepare( 'SELECT DISTINCT(topic) FROM task WHERE topic != ""' );
		$query->execute();
		$result = $query->fetchAll( \PDO::FETCH_ASSOC );
		foreach ( $result as $topic ) {
			$topics = array_merge( $topics, json_decode( $topic['topic'], true ) );
		}
		file_put_contents( 'assets/topics.json', json_encode( array_keys ($topics ),
			JSON_PRETTY_PRINT ) );
	}
}
