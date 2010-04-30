<?php

/**
 * A Mustache implementation in PHP.
 *
 * {@link http://defunkt.github.com/mustache}
 *
 * Mustache is a framework-agnostic logic-less templating language. It enforces separation of view
 * logic from template files. In fact, it is not even possible to embed logic in the template.
 *
 * This is very, very rad.
 *
 * @author Justin Hileman {@link http://justinhileman.com}
 */
class Mustache {

	public $otag = '{{';
	public $ctag = '}}';

	// Should this Mustache throw exceptions when it finds unexpected tags?
	protected $throwSectionExceptions  = true;
	protected $throwPartialExceptions  = false;
	protected $throwVariableExceptions = false;

	// Override charset passed to htmlentities() and htmlspecialchars(). Defaults to UTF-8.
	protected $charset = 'UTF-8';

	const PRAGMA_DOT_NOTATION = 'DOT-NOTATION';

	/**
	 * The {{%UNESCAPED}} pragma swaps the meaning of the {{normal}} and {{{unescaped}}}
	 * Mustache tags. That is, once this pragma is activated the {{normal}} tag will not be
	 * escaped while the {{{unescaped}}} tag will be escaped.
	 *
	 * Pragmas apply only to the current template. Partials, even those included after the
	 * {{%UNESCAPED}} call, will need their own pragma declaration.
	 *
	 * his may be useful in non-HTML Mustache situations.
	 */
	const PRAGMA_UNESCAPED    = 'UNESCAPED';

	protected $tagRegEx;

	protected $template = null;
	protected $context  = array();
	protected $partials = array();
	protected $pragmas  = array();

	protected $pragmasImplemented = array(
		self::PRAGMA_DOT_NOTATION,
		self::PRAGMA_UNESCAPED
	);

	/**
	 * templateBase directory.
	 *
	 * This must be specified in extending classes for the template autoloader to work. I'd suggest
	 * something like:
	 *
	 *     protected $templateBase = __DIR__;
	 *
	 * @var string
	 * @access protected
	 */
	protected $templateBase;

	/**
	 * templateName.
	 *
	 * If none is specified, this will default to an underscorified version of the class name.
	 *
	 * @var string
	 * @access protected
	 */
	protected $templateName;

	/**
	 * File extension used to generate automatic template filenames.
	 *
	 * (default value: 'mustache')
	 *
	 * @var string
	 * @access protected
	 */
	protected $templateExtension = 'mustache';

	/**
	 * Mustache class constructor.
	 *
	 * This method accepts a $template string and a $view object. Optionally, pass an associative
	 * array of partials as well.
	 *
	 * @access public
	 * @param string $template (default: null)
	 * @param mixed $view (default: null)
	 * @param array $partials (default: null)
	 * @return void
	 */
	public function __construct($template = null, $view = null, $partials = null) {
		if ($template !== null) $this->template = $template;
		if ($partials !== null) $this->partials = $partials;
		if ($view !== null)     $this->context = array($view);

		// default template base is the current directory.
		if (isset($this->templateBase)) {
			$this->setTemplateBase($this->templateBase);
		}

		// default template name is the underscorified class name.
		if (!isset($this->templateName)) {
			$this->templateName = strtolower(preg_replace('#(?<=[a-z0-9])([A-Z])#', '_\1', get_class($this)));
		}
	}

	/**
	 * Override the current templateBase.
	 *
	 * @access public
	 * @param string $dir
	 * @return void
	 */
	public function setTemplateBase($dir) {
		if (substr($dir, -1) !== '/') {
			$dir .= '/';
		}
		$this->templateBase = $dir;
	}

	/**
	 * Override the default templateName.
	 *
	 * @access public
	 * @param string $name
	 * @return void
	 */
	public function setTemplateName($name) {
		$this->templateName = $name;
	}

	/**
	 * Load a template file. This file will be relative to $this->templateBase.
	 * A '.mustache' file extension is assumed if none is provided in $file.
	 *
	 * @access public
	 * @param string $name
	 * @return void
	 */
	public function loadTemplate($name) {
		if (strpos($name, '.') === false) {
			$name .= '.' . $this->templateExtension;
		}

		$filename = $this->templateBase . $name;
		if (file_exists($filename)) {
			$this->template = file_get_contents($filename);
		} else {
			$this->template = null;
		}
	}


	/**
	 * Render the given template and view object.
	 *
	 * Defaults to the template and view passed to the class constructor unless a new one is provided.
	 * Optionally, pass an associative array of partials as well.
	 *
	 * @access public
	 * @param string $template (default: null)
	 * @param mixed $view (default: null)
	 * @param array $partials (default: null)
	 * @return string Rendered Mustache template.
	 */
	public function render($template = null, $view = null, $partials = null) {

		// Autoload template if none is explicitly set.
		if ($template === null) {
			if (!isset($this->template)) {
				$this->loadTemplate($this->templateName);
			}
			$template = $this->template;
		}

		if ($partials !== null) $this->partials = $partials;

		if ($view) {
			$this->context = array($view);
		} else if (empty($this->context)) {
			$this->context = array($this);
		}

		return $this->_render($template, $this->context);
	}

	/**
	 * Wrap the render() function for string conversion.
	 *
	 * @access public
	 * @return string
	 */
	public function __toString() {
		// PHP doesn't like exceptions in __toString.
		// catch any exceptions and convert them to strings.
		try {
			$result = $this->render();
			return $result;
		} catch (Exception $e) {
			return "Error rendering mustache: " . $e->getMessage();
		}
	}


	/**
	 * Internal render function, used for recursive calls.
	 *
	 * @access protected
	 * @param string $template
	 * @param array &$context
	 * @return string Rendered Mustache template.
	 */
	protected function _render($template, &$context) {
		$template = $this->renderPragmas($template, $context);
		$template = $this->renderSection($template, $context);
		return $this->renderTags($template, $context);
	}

	/**
	 * Render boolean, enumerable and inverted sections.
	 *
	 * @access protected
	 * @param string $template
	 * @param array $context
	 * @return string
	 */
	protected function renderSection($template, &$context) {
		if (strpos($template, $this->otag . '#') === false) {
			return $template;
		}

		$otag  = $this->prepareRegEx($this->otag);
		$ctag  = $this->prepareRegEx($this->ctag);
		$regex = '/' . $otag . '(\\^|\\#)(.+?)' . $ctag . '\\s*([\\s\\S]+?)' . $otag . '\\/\\2' . $ctag . '\\s*/m';

		$matches = array();
		while (preg_match($regex, $template, $matches, PREG_OFFSET_CAPTURE)) {
			$section  = $matches[0][0];
			$offset   = $matches[0][1];
			$type     = $matches[1][0];
			$tag_name = trim($matches[2][0]);
			$content  = $matches[3][0];

			$replace = '';
			$val = $this->getVariable($tag_name, $context);
			switch($type) {
				// inverted section
				case '^':
					if (empty($val)) {
						$replace .= $content;
					}
					break;

				// regular section
				case '#':
					if ($this->varIsIterable($val)) {
						foreach ($val as $local_context) {
							$c = $this->getContext($context, $local_context);
							$replace .= $this->_render($content, $c);
						}
					} else if ($val) {
						if (is_array($val) || is_object($val)) {
							$c = $this->getContext($context, $val);
							$replace .= $this->_render($content, $c);
						} else {
							$replace .= $content;
						}
					}
					break;
			}

			$template = substr_replace($template, $replace, $offset, strlen($section));
		}

		return $template;
	}

	/**
	 * Initialize pragmas and remove all pragma tags.
	 *
	 * @access protected
	 * @param string $template
	 * @param array &$context
	 * @return string
	 */
	protected function renderPragmas($template, &$context) {
		// no pragmas
		if (strpos($template, $this->otag . '%') === false) {
			return $template;
		}

		$otag = $this->prepareRegEx($this->otag);
		$ctag = $this->prepareRegEx($this->ctag);
		$regex = '/' . $otag . '%\\s*([\\w_-]+)((?: [\\w]+=[\\w]+)*)\\s*' . $ctag . '\\n?/';
		return preg_replace_callback($regex, array($this, 'renderPragma'), $template);
	}

	/**
	 * A preg_replace helper to remove {{%PRAGMA}} tags and enable requested pragma.
	 *
	 * @access protected
	 * @param mixed $matches
	 * @return void
	 * @throws MustacheException unknown pragma
	 */
	protected function renderPragma($matches) {
		$pragma         = $matches[0];
		$pragma_name    = $matches[1];
		$options_string = $matches[2];

		if (!in_array($pragma_name, $this->pragmasImplemented)) {
			throw new MustacheException('Unknown pragma: ' . $pragma_name, MustacheException::UNKNOWN_PRAGMA);
		}

		$options = array();
		foreach (explode(' ', trim($options_string)) as $o) {
			if ($p = trim($o)) {
				$p = explode('=', trim($p));
				$options[$p[0]] = $p[1];
			}
		}

		if (empty($options)) {
			$this->pragmas[$pragma_name] = true;
		} else {
			$this->pragmas[$pragma_name] = $options;
		}

		return '';
	}

	protected function hasPragma($pragma_name) {
		if (array_key_exists($pragma_name, $this->pragmas) && $this->pragmas[$pragma_name]) {
			return true;
		}
	}

	protected function getPragmaOptions($pragma_name) {
		if (!$this->hasPragma()) {
			throw new MustacheException('Unknown pragma: ' . $pragma_name, MustacheException::UNKNOWN_PRAGMA);
		}

		return $this->pragmas[$pragma_name];
	}

	/**
	 * Loop through and render individual Mustache tags.
	 *
	 * @access protected
	 * @param string $template
	 * @param array $context
	 * @return void
	 */
	protected function renderTags($template, &$context) {
		if (strpos($template, $this->otag) === false) {
			return $template;
		}

		$otag = $this->prepareRegEx($this->otag);
		$ctag = $this->prepareRegEx($this->ctag);
		$this->tagRegEx = '/' . $otag . "(#|\/|=|!|>|\\{|&)?([^\/#]+?)\\1?" . $ctag . "+/";
		$html = '';
		$matches = array();
		while (preg_match($this->tagRegEx, $template, $matches, PREG_OFFSET_CAPTURE)) {
			$tag      = $matches[0][0];
			$offset   = $matches[0][1];
			$modifier = $matches[1][0];
			$tag_name = trim($matches[2][0]);

			$html .= substr($template, 0, $offset);
			$html .= $this->renderTag($modifier, $tag_name, $context);
			$template = substr($template, $offset + strlen($tag));
		}

		return $html . $template;
	}

	/**
	 * Render the named tag, given the specified modifier.
	 *
	 * Accepted modifiers are `=` (change delimiter), `!` (comment), `>` (partial)
	 * `{` or `&` (don't escape output), or none (render escaped output).
	 *
	 * @access protected
	 * @param string $modifier
	 * @param string $tag_name
	 * @param array $context
	 * @throws MustacheException Unmatched section tag encountered.
	 * @return string
	 */
	protected function renderTag($modifier, $tag_name, &$context) {
		switch ($modifier) {
			case '#':
				if ($this->throwSectionExceptions) {
					throw new MustacheException('Unclosed section: ' . $tag_name, MustacheException::UNCLOSED_SECTION);
				} else {
					return '';
				}
				break;
			case '/':
				if ($this->throwSectionExceptions) {
					throw new MustacheException('Unexpected close section: ' . $tag_name, MustacheException::UNEXPECTED_CLOSE_SECTION);
				} else {
					return '';
				}
				break;
			case '=':
				return $this->changeDelimiter($tag_name, $context);
				break;
			case '!':
				return $this->renderComment($tag_name, $context);
				break;
			case '>':
				return $this->renderPartial($tag_name, $context);
				break;
			case '{':
			case '&':
				if ($this->hasPragma(self::PRAGMA_UNESCAPED)) {
					return $this->renderEscaped($tag_name, $context);
				} else {
					return $this->renderUnescaped($tag_name, $context);
				}
				break;
			case '':
			default:
				if ($this->hasPragma(self::PRAGMA_UNESCAPED)) {
					return $this->renderUnescaped($tag_name, $context);
				} else {
					return $this->renderEscaped($tag_name, $context);
				}
				break;
		}
	}

	/**
	 * Escape and return the requested tag.
	 *
	 * @access protected
	 * @param string $tag_name
	 * @param array $context
	 * @return string
	 */
	protected function renderEscaped($tag_name, &$context) {
		return htmlentities($this->getVariable($tag_name, $context), null, $this->charset);
	}

	/**
	 * Render a comment (i.e. return an empty string).
	 *
	 * @access protected
	 * @param string $tag_name
	 * @param array $context
	 * @return string
	 */
	protected function renderComment($tag_name, &$context) {
		return '';
	}

	/**
	 * Return the requested tag unescaped.
	 *
	 * @access protected
	 * @param string $tag_name
	 * @param array $context
	 * @return string
	 */
	protected function renderUnescaped($tag_name, &$context) {
		return $this->getVariable($tag_name, $context);
	}

	/**
	 * Render the requested partial.
	 *
	 * @access protected
	 * @param string $tag_name
	 * @param array $context
	 * @return string
	 */
	protected function renderPartial($tag_name, &$context) {
		$view = new self($this->getPartial($tag_name), $context, $this->partials);
		$view->otag = $this->otag;
		$view->ctag = $this->ctag;
		return $view->render();
	}

	/**
	 * Change the Mustache tag delimiter. This method also replaces this object's current
	 * tag RegEx with one using the new delimiters.
	 *
	 * @access protected
	 * @param string $tag_name
	 * @param array $context
	 * @return string
	 */
	protected function changeDelimiter($tag_name, &$context) {
		$tags = explode(' ', $tag_name);
		$this->otag = $tags[0];
		$this->ctag = $tags[1];

		$otag  = $this->prepareRegEx($this->otag);
		$ctag  = $this->prepareRegEx($this->ctag);
		$this->tagRegEx = '/' . $otag . "(#|\/|=|!|>|\\{|&)?([^\/#\^]+?)\\1?" . $ctag . "+/";
		return '';
	}


	/**
	 * Prepare a new context reference array.
	 *
	 * This is used to create context arrays for iterable blocks.
	 *
	 * @access protected
	 * @param array $context
	 * @param mixed $local_context
	 * @return void
	 */
	protected function getContext(&$context, &$local_context) {
		$ret = array();
		$ret[] =& $local_context;
		foreach ($context as $view) {
			$ret[] =& $view;
		}
		return $ret;
	}

	/**
	 * Get a variable from the context array.
	 *
	 * If the view is an array, returns the value with array key $tag_name.
	 * If the view is an object, this will check for a public member variable
	 * named $tag_name. If none is available, this method will execute and return
	 * any class method named $tag_name. Failing all of the above, this method will
	 * return an empty string.
	 *
	 * @access protected
	 * @param string $tag_name
	 * @param array $context
	 * @throws MustacheException Unknown variable name.
	 * @return string
	 */
	protected function getVariable($tag_name, &$context) {
		if ($this->hasPragma(self::PRAGMA_DOT_NOTATION)) {
			$chunks = explode('.', $tag_name);
			$first = array_shift($chunks);

			$ret = $this->_getVariable($first, $context);
			while ($next = array_shift($chunks)) {
				// Slice off a chunk of context for dot notation traversal.
				$c = array($ret);
				$ret = $this->_getVariable($next, $c);
			}
			return $ret;
		} else {
			return $this->_getVariable($tag_name, $context);
		}
	}

	/**
	 * Get a variable from the context array. Internal helper used by getVariable() to abstract
	 * variable traversal for dot notation.
	 *
	 * @access protected
	 * @param string $tag_name
	 * @param array &$context
	 * @throws MustacheException Unknown variable name.
	 * @return string
	 */
	protected function _getVariable($tag_name, &$context) {
		foreach ($context as $view) {
			if (is_object($view)) {
				if (isset($view->$tag_name)) {
					return $view->$tag_name;
				} else if (method_exists($view, $tag_name)) {
					return $view->$tag_name();
				}
			} else if (isset($view[$tag_name])) {
				return $view[$tag_name];
			}
		}

		if ($this->throwVariableExceptions) {
			throw new MustacheException("Unknown variable: " . $tag_name, MustacheException::UNKNOWN_VARIABLE);
		} else {
			return '';
		}
	}

	/**
	 * Retrieve the partial corresponding to the requested tag name.
	 *
	 * Load a partial, either from $this->partials or from a file in the templateBase
	 * directory. If throwPartialExceptions is not enabled, getPartial() silently fails
	 * (i.e. returns '') when the requested partial is not found.
	 *
	 * @access protected
	 * @param string $tag_name
	 * @throws MustacheException Unknown partial name.
	 * @return string
	 */
	protected function getPartial($tag_name) {
		if (is_array($this->partials) && isset($this->partials[$tag_name])) {
			return $this->partials[$tag_name];
		}

		// If no partial is explictly set, search for an appropriately named partial template file.
		$filename = $this->templateBase . $tag_name . '.' . $this->templateExtension;
		if (file_exists($filename)) {
			$this->partials[$tag_name] = file_get_contents($filename);
			return $this->partials[$tag_name];
		}

		if ($this->throwPartialExceptions) {
			throw new MustacheException('Unknown partial: ' . $tag_name, MustacheException::UNKNOWN_PARTIAL);
		}

		return '';
	}

	/**
	 * Check whether the given $var should be iterated (i.e. in a section context).
	 *
	 * @access protected
	 * @param mixed $var
	 * @return bool
	 */
	protected function varIsIterable($var) {
		return is_object($var) || (is_array($var) && !array_diff_key($var, array_keys(array_keys($var))));
	}

	/**
	 * Prepare a string to be used in a regular expression.
	 *
	 * @access protected
	 * @param string $str
	 * @return string
	 */
	protected function prepareRegEx($str) {
		$replace = array(
			'\\' => '\\\\', '^' => '\^', '.' => '\.', '$' => '\$', '|' => '\|', '(' => '\(',
			')' => '\)', '[' => '\[', ']' => '\]', '*' => '\*', '+' => '\+', '?' => '\?',
			'{' => '\{', '}' => '\}', ',' => '\,'
		);
		return strtr($str, $replace);
	}
}


/**
 * MustacheException class.
 *
 * @extends Exception
 */
class MustacheException extends Exception {

	// An UNKNOWN_VARIABLE exception is thrown when a {{variable}} is not found
	// in the current context.
	const UNKNOWN_VARIABLE         = 0;

	// An UNCLOSED_SECTION exception is thrown when a {{#section}} is not closed.
	const UNCLOSED_SECTION         = 1;

	// An UNEXPECTED_CLOSE_SECTION exception is thrown when {{/section}} appears
	// without a corresponding {{#section}}.
	const UNEXPECTED_CLOSE_SECTION = 2;

	// An UNKNOWN_PARTIAL exception is thrown whenever a {{>partial}} tag appears
	// with no associated partial.
	const UNKNOWN_PARTIAL          = 3;

	// An UNKNOWN_PRAGMA exception is thrown whenever a {{%PRAGMA}} tag appears
	// which can't be handled by this Mustache instance.
	const UNKNOWN_PRAGMA           = 4;

}