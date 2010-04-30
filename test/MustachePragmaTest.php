<?php

require_once '../Mustache.php';
require_once 'PHPUnit/Framework.php';

class MustachePragmaTest extends PHPUnit_Framework_TestCase {

	public function testUnknownPragmaException() {
		$m = new Mustache();

		try {
			$m->render('{{%I-HAVE-THE-GREATEST-MUSTACHE}}');
		} catch (MustacheException $e) {
			$this->assertEquals(MustacheException::UNKNOWN_PRAGMA, $e->getCode(), 'Caught exception code was not MustacheException::UNKNOWN_PRAGMA');
			return;
		}

		$this->fail('Mustache should have thrown an unknown pragma exception');
	}

	public function testPragmaReplace() {
		$m = new Mustache();
		$this->assertEquals($m->render('{{%DOT-NOTATION}}'), '', 'Pragma tag not removed');
	}

	public function testPragmaReplaceMultiple() {
		$m = new Mustache();
		$this->assertEquals('', $m->render('{{%  DOT-NOTATION  }}'), 'Pragmas should allow whitespace');
		$this->assertEquals('', $m->render('{{% 	DOT-NOTATION 	foo=bar  }}'), 'Pragmas should allow whitespace');
		$this->assertEquals($m->render("{{%DOT-NOTATION}}\n{{%DOT-NOTATION}}"), '', 'Multiple pragma tags not removed');
		$this->assertEquals($m->render('{{%DOT-NOTATION}} {{%DOT-NOTATION}}'), ' ', 'Multiple pragma tags not removed');
	}

	public function testPragmaReplaceNewline() {
		$m = new Mustache();
		$this->assertEquals($m->render("{{%DOT-NOTATION}}\n"), '', 'Trailing newline after pragma tag not removed');
		$this->assertEquals($m->render("\n{{%DOT-NOTATION}}\n"), "\n", 'Too many newlines removed with pragma tag');
		$this->assertEquals($m->render("1\n2{{%DOT-NOTATION}}\n3"), "1\n23", 'Wrong newline removed with pragma tag');
	}

}