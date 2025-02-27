<?php declare(strict_types=1);

# @author noodlehaus
# @license MIT

define('DISPATCH_ROUTES_KEY', '__dispatch_routes__');
define('DISPATCH_MIDDLEWARE_KEY', '__dispatch_middleware__');
define('DISPATCH_BINDINGS_KEY', '__dispatch_bindings__');

if (!defined('DISPATCH_PATH_PREFIX')) {
  define('DISPATCH_PATH_PREFIX', '');
}

# sets or gets a value in a request-scope storage
function stash(string $key, mixed $value = null): mixed {
  static $store = [];
  return match(func_num_args()) {
    1 => $store[$key] ?? null,
    2 => ($store[$key] = $value),
    default => throw new BadFunctionCallException('Unsupported function call.'),
  };
}

# dispatch sapi request against routes context
function dispatch(...$args): void {

  $method = strtoupper($_SERVER['REQUEST_METHOD']);
  $path = '/'.trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

  $path = substr($path, strlen(DISPATCH_PATH_PREFIX));

  # post method override
  if ($method === 'POST') {
    if (isset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'])) {
      $method = strtoupper($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']);
    } else {
      $method = isset($_POST['_method']) ? strtoupper($_POST['_method']) : $method;
    }
  }

  $responder = serve(stash(DISPATCH_ROUTES_KEY) ?? [], $method, $path, ...$args);
  $responder();
}

# creates an action and puts it into the routes stack
function route(string $method, string $path, callable ...$handlers): void {
  $routes = stash(DISPATCH_ROUTES_KEY) ?? [];
  array_push($routes, action($method, $path, ...$handlers));
  stash(DISPATCH_ROUTES_KEY, $routes);
}

# set a custom 404 handler for non-matching requests
function _404(callable $handler = null): callable {
  static $error404 = null;
  if (func_num_args() === 0) {
    return is_null($error404)
      ? fn() => response('Not Found', 404)
      : $error404;
  }
  return ($error404 = $handler);
}

# allows middleware mapping against paths
function apply(...$args): void {

  if (empty($args)) {
    throw new BadFunctionCallException('Unsupported function call.');
  }

  $regexp = array_shift($args);
  $record = is_string($regexp)
    ? ["@{$regexp}@", ...$args]
    : ['@.+@', $regexp];

  $mwares = stash(DISPATCH_MIDDLEWARE_KEY) ?? [];
  $mwares[] = $record;
  stash(DISPATCH_MIDDLEWARE_KEY, $mwares);
}

# maps a callback/mutation against a route named parameter
function bind(string $name, callable $transform): void {
  $bindings = stash(DISPATCH_BINDINGS_KEY) ?? [];
  $bindings[$name] = $transform;
  stash(DISPATCH_BINDINGS_KEY, $bindings);
}

# creates a route handler
function action(string $method, string $path, callable ...$handlers): array {
  $regexp = '@^'.preg_replace('@:(\w+)@', '(?<\1>[^/]+)', $path).'$@';
  return [strtoupper($method), $regexp, $handlers];
}

# creates standard response
function response(string $body, int $code = 200, array $headers = []): callable {
  return fn() => render($body, $code, $headers);
}

# creates redirect response
function redirect(string $location, int $code = 302): callable {
  return fn() => render('', $code, ['location' => $location]);
}

# dispatches method + path against route stack
function serve(array $routes, string $reqmethod, string $reqpath, ...$args): callable {

  $action = null;
  $params = null;
  $mwares = null;

  # test method + path against action method + expression
  foreach ($routes as [$actmethod, $regexp, $handlers]) {
    if ($reqmethod === $actmethod && preg_match($regexp, $reqpath, $caps)) {
      # action is last in the handlers chain
      $action = array_pop($handlers);
      $params = array_slice($caps, 1);
      $mwares = $handlers;
      break;
    }
  }

  # no matching route, 404
  if (empty($action)) {
    return call_user_func(_404(), ...$args);
  }

  # if we have params, run them through bindings
  $bindings = stash(DISPATCH_BINDINGS_KEY) ?? [];
  if (count($params) && count($bindings)) {
    foreach ($params as $key => $val) {
      $params[$key] = isset($bindings[$key])
        ? call_user_func($bindings[$key], $params[$key], ...$args)
        : $params[$key];
    }
  }

  # wrap action as last midware chain link
  $next = function () use ($action, $params, $args) {
    return empty($params)
      ? $action(...$args)
      : $action($params, ...$args);
  };

  # prepend matching global middleware into middleware chain
  $globalmwares = array_reverse(stash(DISPATCH_MIDDLEWARE_KEY) ?? []);
  foreach ($globalmwares as $middleware) {
    $pattern = array_shift($middleware);
    if (preg_match($pattern, $reqpath)) {
      array_unshift($mwares, ...$middleware);
    }
  }

  # build midware chain, from last to first, if any
  foreach (array_reverse($mwares) as $middleware) {
    $next = function () use ($middleware, $next, $params, $args) {
      return empty($params)
        ? $middleware($next, ...$args)
        : $middleware($next, $params, ...$args);
    };
  }

  # trigger middleware chain + handlers
  return $next();
}

# renders request response to the output buffer (ref: zend-diactoros)
function render(string $body, int $code = 200, array $headers = []): void {
  http_response_code($code);
  array_walk($headers, function ($value, $key) {
    if (!preg_match('/^[a-zA-Z0-9\'`#$%&*+.^_|~!-]+$/', $key)) {
      throw new InvalidArgumentException("Invalid header name - {$key}");
    }
    $values = is_array($value) ? $value : [$value];
    foreach ($values as $val) {
      if (
        preg_match("#(?:(?:(?<!\r)\n)|(?:\r(?!\n))|(?:\r\n(?![ \t])))#", $val) ||
        preg_match('/[^\x09\x0a\x0d\x20-\x7E\x80-\xFE]/', $val)
      ) {
        throw new InvalidArgumentException("Invalid header value - {$val}");
      }
    }
    header($key.': '.implode(',', $values));
  });
  !empty($body) && print $body;
}

# renders and returns the content of a template
function phtml(string $path, array $vars = []): string {
  if (!preg_match('@\.phtml$@', $path)) {
    $path = "{$path}.phtml";
  }
  ob_start();
  extract($vars, EXTR_SKIP);
  require $path;
  return trim(ob_get_clean());
}
