<?php

# @author Jesus A. Domingo <jesus.domingo@gmail.com>
# @license MIT

# creates a route handler
function action($verb, $path, callable $func) {
  return function ($rverb, $rpath) use ($verb, $path, $func) {
    $rexp = preg_replace('@:(\w+)@', '(?<\1>[^/]+)', $path);
    if (
      strtoupper($rverb) !== strtoupper($verb) ||
      !preg_match("@^{$rexp}$@", $rpath, $caps)
    ) {
      return [];
    }
    return [$func, array_slice($caps, 1)];
  };
}

# returns by ref the route stack singleton
function &context() {
  static $context = [];
  return $context;
}

# dispatch sapi request against routes context
function dispatch(...$args) {

  $verb = strtoupper($_SERVER['REQUEST_METHOD']);
  $path = '/'.trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

  # post method override
  if ($verb === 'POST') {
    if (isset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'])) {
      $verb = strtoupper($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']);
    } else {
      $verb = isset($_POST['_method']) ? strtoupper($_POST['_method']) : $verb;
    }
  }

  $resp = serve(context(), $verb, $path, ...$args);

  render(...$resp);
}

# performs a lookup against actions for verb + path
function match(array $actions, $verb, $path) {

  $cverb = strtoupper(trim($verb));
  $cpath = '/'.trim(rawurldecode(parse_url($path, PHP_URL_PATH)), '/');

  # test verb + path against route handlers
  foreach ($actions as $test) {
    $match = $test($cverb, $cpath);
    if (!empty($match)) {
      return $match;
    }
  }

  return [];
}

# creates an page-rendering action
function page($path, array $vars = []) {
  return function () use ($path, $vars) {
    return response(phtml($path, $vars));
  };
}

# renders and returns the content of a template
function phtml($path, array $vars = []) {
  ob_start();
  extract($vars, EXTR_SKIP);
  require "{$path}.phtml";
  return trim(ob_get_clean());
}

# creates redirect response
function redirect($location, $status = 302) {
  return ['', $status, ['location' => $location]];
}

# renders request response to the output buffer
function render($content, $status = 200, $headers = []) {

  http_response_code($status);

  array_walk($headers, function ($val, $key) {

    # validate header key (ref: zend-diactoros)
    if (! preg_match('/^[a-zA-Z0-9\'`#$%&*+.^_|~!-]+$/', $key)) {
      throw new InvalidArgumentException("Invalid header name - {$key}");
    }

    # validate header value (ref: zend-diactoros)
    if (
      preg_match("#(?:(?:(?<!\r)\n)|(?:\r(?!\n))|(?:\r\n(?![ \t])))#", $val) ||
      preg_match('/[^\x09\x0a\x0d\x20-\x7E\x80-\xFE]/', $val)
    ) {
      throw new InvalidArgumentException("Invalid header value - {$val}");
    }

    header("{$key}: {$val}", true);
  });
  return print $content;
}

# creates standard response
function response($content, $status = 200, array $headers = []) {
  return [$content, $status, $headers];
}

# creates json response
function jsonResponse($data, $status = 200, array $headers = []) {
  $headers['Content-Type'] = 'application/json';
  return [json_encode($data), $status, $headers];
}

# creates an action and puts it into the routes stack
function route($verb, $path, callable $func) {
  $context = &context();
  array_push($context, action($verb, $path, $func));
}

# dispatches current request against route handlers
function serve(array $app, $verb, $path, ...$args) {
  $pair = match($app, $verb, $path);
  $func = array_shift($pair) ?: function () { return response('', 404, []); };
  $caps = array_shift($pair) ?: null;
  return empty($caps) ? $func(...$args) : $func($caps, ...$args);
}
