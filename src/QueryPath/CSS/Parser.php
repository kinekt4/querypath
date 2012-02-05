<?php
/**
 * @file
 *
 * The CSS parser
 */

namespace QueryPath\CSS;

/**
 * Parse a CSS selector.
 *
 * In CSS, a selector is used to identify which element or elements
 * in a DOM are being selected for the application of a particular style.
 * Effectively, selectors function as a query language for a structured
 * document -- almost always HTML or XML.
 *
 * This class provides an event-based parser for CSS selectors. It can be
 * used, for example, as a basis for writing a DOM query engine based on
 * CSS.
 *
 * @ingroup querypath_css
 */
class Parser {
  protected $scanner = NULL;
  protected $buffer = '';
  protected $handler = NULL;
  protected $strict = FALSE;

  protected $DEBUG = FALSE;

  /**
   * Construct a new CSS parser object. This will attempt to
   * parse the string as a CSS selector. As it parses, it will
   * send events to the EventHandler implementation.
   */
  public function __construct($string, EventHandler $handler) {
    $this->originalString = $string;
    $is = new InputStream($string);
    $this->scanner = new CssScanner($is);
    $this->handler = $handler;
  }

  /**
   * Parse the selector.
   *
   * This begins an event-based parsing process that will
   * fire events as the selector is handled. A EventHandler
   * implementation will be responsible for handling the events.
   * @throws CssParseException
   */
  public function parse() {

    $this->scanner->nextToken();
    while ($this->scanner->token !== FALSE) {
      // Primitive recursion detection.
      $position = $this->scanner->position();

      if ($this->DEBUG) {
        print "PARSE " . $this->scanner->token. "\n";
      }
      $this->selector();

      $finalPosition = $this->scanner->position();

      if ($this->scanner->token !== FALSE && $finalPosition == $position) {
        // If we get here, then the scanner did not pop a single character
        // off of the input stream during a full run of the parser, which
        // means that the current input does not match any recognizable
        // pattern.
        throw new CssParseException('CSS selector is not well formed.');
      }

    }

  }

  /**
   * A restricted parser that can only parse simple selectors.
   * The pseudoClass handler for this parser will throw an
   * exception if it encounters a pseudo-element or the
   * negation pseudo-class.
   *
   * @deprecated This is not used anywhere in QueryPath and
   *  may be removed.
   *//*
  public function parseSimpleSelector() {
    while ($this->scanner->token !== FALSE) {
      if ($this->DEBUG) print "SIMPLE SELECTOR\n";
      $this->allElements();
      $this->elementName();
      $this->elementClass();
      $this->elementID();
      $this->pseudoClass(TRUE); // Operate in restricted mode.
      $this->attribute();

      // TODO: Need to add failure conditions here.
    }
  }*/

  /**
   * Handle an entire CSS selector.
   */
  private function selector() {
    if ($this->DEBUG) print "SELECTOR{$this->scanner->position()}\n";
    $this->consumeWhitespace(); // Remove leading whitespace
    $this->simpleSelectors();
    $this->combinator();
  }

  /**
   * Consume whitespace and return a count of the number of whitespace consumed.
   */
  private function consumeWhitespace() {
    if ($this->DEBUG) print "CONSUME WHITESPACE\n";
    $white = 0;
    while ($this->scanner->token == CssToken::white) {
      $this->scanner->nextToken();
      ++$white;
    }
    return $white;
  }

  /**
   * Handle one of the five combinators: '>', '+', ' ', '~', and ','.
   * This will call the appropriate event handlers.
   * @see EventHandler::directDescendant(),
   * @see EventHandler::adjacent(),
   * @see EventHandler::anyDescendant(),
   * @see EventHandler::anotherSelector().
   */
  private function combinator() {
    if ($this->DEBUG) print "COMBINATOR\n";
    /*
     * Problem: ' ' and ' > ' are both valid combinators.
     * So we have to track whitespace consumption to see
     * if we are hitting the ' ' combinator or if the
     * selector just has whitespace padding another combinator.
     */

    // Flag to indicate that post-checks need doing
    $inCombinator = FALSE;
    $white = $this->consumeWhitespace();
    $t = $this->scanner->token;

    if ($t == CssToken::rangle) {
      $this->handler->directDescendant();
      $this->scanner->nextToken();
      $inCombinator = TRUE;
      //$this->simpleSelectors();
    }
    elseif ($t == CssToken::plus) {
      $this->handler->adjacent();
      $this->scanner->nextToken();
      $inCombinator = TRUE;
      //$this->simpleSelectors();
    }
    elseif ($t == CssToken::comma) {
      $this->handler->anotherSelector();
      $this->scanner->nextToken();
      $inCombinator = TRUE;
      //$this->scanner->selectors();
    }
    elseif ($t == CssToken::tilde) {
      $this->handler->sibling();
      $this->scanner->nextToken();
      $inCombinator = TRUE;
    }

    // Check that we don't get two combinators in a row.
    if ($inCombinator) {
      $white = 0;
      if ($this->DEBUG) print "COMBINATOR: " . CssToken::name($t) . "\n";
      $this->consumeWhitespace();
      if ($this->isCombinator($this->scanner->token)) {
        throw new CssParseException("Illegal combinator: Cannot have two combinators in sequence.");
      }
    }
    // Check to see if we have whitespace combinator:
    elseif ($white > 0) {
      if ($this->DEBUG) print "COMBINATOR: any descendant\n";
      $inCombinator = TRUE;
      $this->handler->anyDescendant();
    }
    else {
      if ($this->DEBUG) print "COMBINATOR: no combinator found.\n";
    }
  }

  /**
   * Check if the token is a combinator.
   */
  private function isCombinator($tok) {
    $combinators = array(CssToken::plus, CssToken::rangle, CssToken::comma, CssToken::tilde);
    return in_array($tok, $combinators);
  }

  /**
   * Handle a simple selector.
   */
  private function simpleSelectors() {
    if ($this->DEBUG) print "SIMPLE SELECTOR\n";
    $this->allElements();
    $this->elementName();
    $this->elementClass();
    $this->elementID();
    $this->pseudoClass();
    $this->attribute();
  }

  /**
   * Handles CSS ID selectors.
   * This will call EventHandler::elementID().
   */
  private function elementID() {
    if ($this->DEBUG) print "ELEMENT ID\n";
    if ($this->scanner->token == CssToken::octo) {
      $this->scanner->nextToken();
      if ($this->scanner->token !== CssToken::char) {
        throw new CssParseException("Expected string after #");
      }
      $id = $this->scanner->getNameString();
      $this->handler->elementID($id);
    }
  }

  /**
   * Handles CSS class selectors.
   * This will call the EventHandler::elementClass() method.
   */
  private function elementClass() {
    if ($this->DEBUG) print "ELEMENT CLASS\n";
    if ($this->scanner->token == CssToken::dot) {
      $this->scanner->nextToken();
      $this->consumeWhitespace(); // We're very fault tolerent. This should prob through error.
      $cssClass = $this->scanner->getNameString();
      $this->handler->elementClass($cssClass);
    }
  }

  /**
   * Handle a pseudo-class and pseudo-element.
   *
   * CSS 3 selectors support separate pseudo-elements, using :: instead
   * of : for separator. This is now supported, and calls the pseudoElement
   * handler, EventHandler::pseudoElement().
   *
   * This will call EventHandler::pseudoClass() when a
   * pseudo-class is parsed.
   */
  private function pseudoClass($restricted = FALSE) {
    if ($this->DEBUG) print "PSEUDO-CLASS\n";
    if ($this->scanner->token == CssToken::colon) {

      // Check for CSS 3 pseudo element:
      $isPseudoElement = FALSE;
      if ($this->scanner->nextToken() === CssToken::colon) {
        $isPseudoElement = TRUE;
        $this->scanner->nextToken();
      }

      $name = $this->scanner->getNameString();
      if ($restricted && $name == 'not') {
        throw new CssParseException("The 'not' pseudo-class is illegal in this context.");
      }

      $value = NULL;
      if ($this->scanner->token == CssToken::lparen) {
        if ($isPseudoElement) {
          throw new CssParseException("Illegal left paren. Pseudo-Element cannot have arguments.");
        }
        $value = $this->pseudoClassValue();
      }

      // FIXME: This should throw errors when pseudo element has values.
      if ($isPseudoElement) {
        if ($restricted) {
          throw new CssParseException("Pseudo-Elements are illegal in this context.");
        }
        $this->handler->pseudoElement($name);
        $this->consumeWhitespace();

        // Per the spec, pseudo-elements must be the last items in a selector, so we
        // check to make sure that we are either at the end of the stream or that a
        // new selector is starting. Only one pseudo-element is allowed per selector.
        if ($this->scanner->token !== FALSE && $this->scanner->token !== CssToken::comma) {
          throw new CssParseException("A Pseudo-Element must be the last item in a selector.");
        }
      }
      else {
        $this->handler->pseudoClass($name, $value);
      }
    }
  }

  /**
   * Get the value of a pseudo-classes.
   *
   * @return string
   *  Returns the value found from a pseudo-class.
   *
   * @todo Pseudoclasses can be passed pseudo-elements and
   *  other pseudo-classes as values, which means :pseudo(::pseudo)
   *  is legal.
   */
  private function pseudoClassValue() {
    if ($this->scanner->token == CssToken::lparen) {
      $buf = '';

      // For now, just leave pseudoClass value vague.
      /*
      // We have to peek to see if next char is a colon because
      // pseudo-classes and pseudo-elements are legal strings here.
      print $this->scanner->peek();
      if ($this->scanner->peek() == ':') {
        print "Is pseudo\n";
        $this->scanner->nextToken();

        // Pseudo class
        if ($this->scanner->token == CssToken::colon) {
          $buf .= ':';
          $this->scanner->nextToken();
          // Pseudo element
          if ($this->scanner->token == CssToken::colon) {
            $buf .= ':';
            $this->scanner->nextToken();
          }
          // Ident
          $buf .= $this->scanner->getNameString();
        }
      }
      else {
        print "fetching string.\n";
        $buf .= $this->scanner->getQuotedString();
        if ($this->scanner->token != CssToken::rparen) {
          $this->throwError(CssToken::rparen, $this->scanner->token);
        }
        $this->scanner->nextToken();
      }
      return $buf;
      */
      $buf .= $this->scanner->getQuotedString();
      return $buf;
    }
  }

  /**
   * Handle element names.
   * This will call the EventHandler::elementName().
   *
   * This handles:
   * <code>
   *  name (EventHandler::element())
   *  |name (EventHandler::element())
   *  ns|name (EventHandler::elementNS())
   *  ns|* (EventHandler::elementNS())
   * </code>
   */
  private function elementName() {
    if ($this->DEBUG) print "ELEMENT NAME\n";
    if ($this->scanner->token === CssToken::pipe) {
      // We have '|name', which is equiv to 'name'
      $this->scanner->nextToken();
      $this->consumeWhitespace();
      $elementName =  $this->scanner->getNameString();
      $this->handler->element($elementName);
    }
    elseif ($this->scanner->token === CssToken::char) {
      $elementName =  $this->scanner->getNameString();
      if ($this->scanner->token == CssToken::pipe) {
        // Get ns|name
        $elementNS = $elementName;
        $this->scanner->nextToken();
        $this->consumeWhitespace();
        if ($this->scanner->token === CssToken::star) {
          // We have ns|*
          $this->handler->anyElementInNS($elementNS);
          $this->scanner->nextToken();
        }
        elseif ($this->scanner->token !== CssToken::char) {
          $this->throwError(CssToken::char, $this->scanner->token);
        }
        else {
          $elementName = $this->scanner->getNameString();
          // We have ns|name
          $this->handler->elementNS($elementName, $elementNS);
        }

      }
      else {
        $this->handler->element($elementName);
      }
    }
  }

  /**
   * Check for all elements designators. Due to the new CSS 3 namespace
   * support, this is slightly more complicated, now, as it handles
   * the *|name and *|* cases as well as *.
   *
   * Calls EventHandler::anyElement() or EventHandler::elementName().
   */
  private function allElements() {
    if ($this->scanner->token === CssToken::star) {
      $this->scanner->nextToken();
      if ($this->scanner->token === CssToken::pipe) {
        $this->scanner->nextToken();
        if ($this->scanner->token === CssToken::star) {
          // We got *|*. According to spec, this requires
          // that the element has a namespace, so we pass it on
          // to the handler:
          $this->scanner->nextToken();
          $this->handler->anyElementInNS('*');
        }
        else {
          // We got *|name, which means the name MUST be in a namespce,
          // so we pass this off to elementNameNS().
          $name = $this->scanner->getNameString();
          $this->handler->elementNS($name, '*');
        }
      }
      else {
        $this->handler->anyElement();
      }
    }
  }

  /**
   * Handler an attribute.
   * An attribute can be in one of two forms:
   * <code>[attrName]</code>
   * or
   * <code>[attrName="AttrValue"]</code>
   *
   * This may call the following event handlers: EventHandler::attribute().
   */
  private function attribute() {
    if($this->scanner->token == CssToken::lsquare) {
      $attrVal = $op = $ns = NULL;

      $this->scanner->nextToken();
      $this->consumeWhitespace();

      if ($this->scanner->token === CssToken::at) {
        if ($this->strict) {
          throw new CssParseException('The @ is illegal in attributes.');
        }
        else {
          $this->scanner->nextToken();
          $this->consumeWhitespace();
        }
      }

      if ($this->scanner->token === CssToken::star) {
        // Global namespace... requires that attr be prefixed,
        // so we pass this on to a namespace handler.
        $ns = '*';
        $this->scanner->nextToken();
      }
      if ($this->scanner->token === CssToken::pipe) {
        // Skip this. It's a global namespace.
        $this->scanner->nextToken();
        $this->consumeWhitespace();
      }

      $attrName = $this->scanner->getNameString();
      $this->consumeWhitespace();

      // Check for namespace attribute: ns|attr. We have to peek() to make
      // sure that we haven't hit the |= operator, which looks the same.
      if ($this->scanner->token === CssToken::pipe && $this->scanner->peek() !== '=') {
        // We have a namespaced attribute.
        $ns = $attrName;
        $this->scanner->nextToken();
        $attrName = $this->scanner->getNameString();
        $this->consumeWhitespace();
      }

      // Note: We require that operators do not have spaces
      // between characters, e.g. ~= , not ~ =.

      // Get the operator:
      switch ($this->scanner->token) {
        case CssToken::eq:
          $this->consumeWhitespace();
          $op = EventHandler::isExactly;
          break;
        case CssToken::tilde:
          if ($this->scanner->nextToken() !== CssToken::eq) {
            $this->throwError(CssToken::eq, $this->scanner->token);
          }
          $op = EventHandler::containsWithSpace;
          break;
        case CssToken::pipe:
          if ($this->scanner->nextToken() !== CssToken::eq) {
            $this->throwError(CssToken::eq, $this->scanner->token);
          }
          $op = EventHandler::containsWithHyphen;
          break;
        case CssToken::star:
          if ($this->scanner->nextToken() !== CssToken::eq) {
            $this->throwError(CssToken::eq, $this->scanner->token);
          }
          $op = EventHandler::containsInString;
          break;
        case CssToken::dollar;
          if ($this->scanner->nextToken() !== CssToken::eq) {
            $this->throwError(CssToken::eq, $this->scanner->token);
          }
          $op = EventHandler::endsWith;
          break;
        case CssToken::carat:
          if ($this->scanner->nextToken() !== CssToken::eq) {
            $this->throwError(CssToken::eq, $this->scanner->token);
          }
          $op = EventHandler::beginsWith;
          break;
      }

      if (isset($op)) {
        // Consume '=' and go on.
        $this->scanner->nextToken();
        $this->consumeWhitespace();

        // So... here we have a problem. The grammer suggests that the
        // value here is String1 or String2, both of which are enclosed
        // in quotes of some sort, and both of which allow lots of special
        // characters. But the spec itself includes examples like this:
        //   [lang=fr]
        // So some bareword support is assumed. To get around this, we assume
        // that bare words follow the NAME rules, while quoted strings follow
        // the String1/String2 rules.

        if ($this->scanner->token === CssToken::quote || $this->scanner->token === CssToken::squote) {
          $attrVal = $this->scanner->getQuotedString();
        }
        else {
          $attrVal = $this->scanner->getNameString();
        }

        if ($this->DEBUG) {
          print "ATTR: $attrVal AND OP: $op\n";
        }
      }

      $this->consumeWhitespace();

      if ($this->scanner->token != CssToken::rsquare) {
        $this->throwError(CssToken::rsquare, $this->scanner->token);
      }

      if (isset($ns)) {
        $this->handler->attributeNS($attrName, $ns, $attrVal, $op);
      }
      elseif (isset($attrVal)) {
        $this->handler->attribute($attrName, $attrVal, $op);
      }
      else {
        $this->handler->attribute($attrName);
      }
      $this->scanner->nextToken();
    }
  }

  /**
   * Utility for throwing a consistantly-formatted parse error.
   */
  private function throwError($expected, $got) {
    $filter = sprintf('Expected %s, got %s', CssToken::name($expected), CssToken::name($got));
    throw new CssParseException($filter);
  }

}

/**
 * Scanner for CSS selector parsing.
 *
 * This provides a simple scanner for traversing an input stream.
 *
 * @ingroup querypath_css
 */
final class CssScanner {
  var $is = NULL;
  public $value = NULL;
  public $token = NULL;

  var $recurse = FALSE;
  var $it = 0;

  /**
   * Given a new input stream, tokenize the CSS selector string.
   * @see InputStream
   * @param InputStream $in
   *  An input stream to be scanned.
   */
  public function __construct(InputStream $in) {
    $this->is = $in;
  }

  /**
   * Return the position of the reader in the string.
   */
  public function position() {
    return $this->is->position;
  }

  /**
   * See the next char without removing it from the stack.
   *
   * @return char
   *  Returns the next character on the stack.
   */
  public function peek() {
    return $this->is->peek();
  }

  /**
   * Get the next token in the input stream.
   *
   * This sets the current token to the value of the next token in
   * the stream.
   *
   * @return int
   *  Returns an int value corresponding to one of the CssToken constants,
   *  or FALSE if the end of the string is reached. (Remember to use
   *  strong equality checking on FALSE, since 0 is a valid token id.)
   */
  public function nextToken() {
    $tok = -1;
    ++$this->it;
    if ($this->is->isEmpty()) {
      if ($this->recurse) {
        throw new Exception("Recursion error detected at iteration " . $this->it . '.');
        exit();
      }
      //print "{$this->it}: All done\n";
      $this->recurse = TRUE;
      $this->token = FALSE;
      return FALSE;
    }
    $ch = $this->is->consume();
    //print __FUNCTION__ . " Testing $ch.\n";
    if (ctype_space($ch)) {
      $this->value = ' '; // Collapse all WS to a space.
      $this->token = $tok = CssToken::white;
      //$ch = $this->is->consume();
      return $tok;
    }

    if (ctype_alnum($ch) || $ch == '-' || $ch == '_') {
      // It's a character
      $this->value = $ch; //strtolower($ch);
      $this->token = $tok = CssToken::char;
      return $tok;
    }

    $this->value = $ch;

    switch($ch) {
      case '*':
        $tok = CssToken::star;
        break;
      case chr(ord('>')):
        $tok = CssToken::rangle;
        break;
      case '.':
        $tok = CssToken::dot;
        break;
      case '#':
        $tok = CssToken::octo;
        break;
      case '[':
        $tok = CssToken::lsquare;
        break;
      case ']':
        $tok = CssToken::rsquare;
        break;
      case ':':
        $tok = CssToken::colon;
        break;
      case '(':
        $tok = CssToken::lparen;
        break;
      case ')':
        $tok = CssToken::rparen;
        break;
      case '+':
        $tok = CssToken::plus;
        break;
      case '~':
        $tok = CssToken::tilde;
        break;
      case '=':
        $tok = CssToken::eq;
        break;
      case '|':
        $tok = CssToken::pipe;
        break;
      case ',':
        $tok = CssToken::comma;
        break;
      case chr(34):
        $tok = CssToken::quote;
        break;
      case "'":
        $tok = CssToken::squote;
        break;
      case '\\':
        $tok = CssToken::bslash;
        break;
      case '^':
        $tok = CssToken::carat;
        break;
      case '$':
        $tok = CssToken::dollar;
        break;
      case '@':
        $tok = CssToken::at;
        break;
    }


    // Catch all characters that are legal within strings.
    if ($tok == -1) {
      // TODO: This should be UTF-8 compatible, but PHP doesn't
      // have a native UTF-8 string. Should we use external
      // mbstring library?

      $ord = ord($ch);
      // Characters in this pool are legal for use inside of
      // certain strings. Extended ASCII is used here, though I
      // Don't know if these are really legal.
      if (($ord >= 32 && $ord <= 126) || ($ord >= 128 && $ord <= 255)) {
        $tok = CssToken::stringLegal;
      }
      else {
        throw new CSSParseException('Illegal character found in stream: ' . $ord);
      }
    }

    $this->token = $tok;
    return $tok;
  }

  /**
   * Get a name string from the input stream.
   * A name string must be composed of
   * only characters defined in CssToken:char: -_a-zA-Z0-9
   */
  public function getNameString() {
    $buf = '';
    while ($this->token === CssToken::char) {
      $buf .= $this->value;
      $this->nextToken();
      //print '_';
    }
    return $buf;
  }

  /**
   * This gets a string with any legal 'string' characters.
   * See CSS Selectors specification, section 11, for the
   * definition of string.
   *
   * This will check for string1, string2, and the case where a
   * string is unquoted (Oddly absent from the "official" grammar,
   * though such strings are present as examples in the spec.)
   *
   * Note:
   * Though the grammar supplied by CSS 3 Selectors section 11 does not
   * address the contents of a pseudo-class value, the spec itself indicates
   * that a pseudo-class value is a "value between parenthesis" [6.6]. The
   * examples given use URLs among other things, making them closer to the
   * definition of 'string' than to 'name'. So we handle them here as strings.
   */
  public function getQuotedString() {
    if ($this->token == CssToken::quote || $this->token == CssToken::squote || $this->token == CssToken::lparen) {
      $end = ($this->token == CssToken::lparen) ? CssToken::rparen : $this->token;
      $buf = '';
      $escape = FALSE;

      $this->nextToken(); // Skip the opening quote/paren

      // The second conjunct is probably not necessary.
      while ($this->token !== FALSE && $this->token > -1) {
        //print "Char: $this->value \n";
        if ($this->token == CssToken::bslash && !$escape) {
          // XXX: The backslash (\) is removed here.
          // Turn on escaping.
          //$buf .= $this->value;
          $escape = TRUE;
        }
        elseif ($escape) {
          // Turn off escaping
          $buf .= $this->value;
          $escape = FALSE;
        }
        elseif ($this->token === $end) {
          // At end of string; skip token and break.
          $this->nextToken();
          break;
        }
        else {
          // Append char.
          $buf .= $this->value;
        }
        $this->nextToken();
      }
      return $buf;
    }
  }

  /**
   * Get a string from the input stream.
   * This is a convenience function for getting a string of
   * characters that are either alphanumber or whitespace. See
   * the CssToken::white and CssToken::char definitions.
   *
   * @deprecated This is not used anywhere in QueryPath.
   *//*
  public function getStringPlusWhitespace() {
    $buf = '';
    if($this->token === FALSE) {return '';}
    while ($this->token === CssToken::char || $this->token == CssToken::white) {
      $buf .= $this->value;
      $this->nextToken();
    }
    return $buf;
  }*/

}
