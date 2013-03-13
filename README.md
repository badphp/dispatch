## Dispatch PHP 5.3 Utility Library
At the very least, `dispatch()` is a front controller for your web app. It doesn't give you the full MVC setup, but it lets you define url routes and segregate your app logic from your views.

### Requirements
* PHP 5.3
* `mcrypt` extension if you want to use encrypted cookies and wish to use `encrypt()` and `decrypt()` functions
* `apc` extension if you want to use `cache()` and `cache_invalidate()`

### Configuration Variables
The following functions rely on variables set via `config()`:
* `config('debug.log')` is used by `_log()` as the destination log file
* `config('debug.enable')` dictates if `_log()` does something or not
* `config('views.root')` is used by `render()` and `partial()`, defaults to `./views`
* `config('views.layout')` is used by `render()`, defaults to `layout`
* `config('cookies.secret')` is used by `encrypt()`, `decrypt()`, `set_cookie()` and `get_cookie()`, defaults to an empty string
* `config('cookies.flash')` is used by `flash()` for setting messages
* `config('site.url')` is used by `site_url()` and `site_path()`
* `config('source')` makes the specified ini contents accessible via `config()` calls

### Quick and Basic
A typical PHP app using dispatch() will look like this.

```php
<?php
// include the library
include 'dispatch.php';

// define your routes
get('/greet', function () {
	// render a view
	render('greet-form');
});

// post handler
post('/greet', function () {
	$name = from($_POST, 'name');
	// render a view while passing some locals
	render('greet-show', array('name' => $name));
});

// serve your site
dispatch();
?>
```

### Route Symbol Filters
This is taken from ExpressJS. Route filters let you map functions against symbols in your routes. These functions then get executed when those symbols are matched.

```php
<?php
// preload blog entry whenever a matching route has :blog_id in it
filter('blog_id', function ($blog_id) {
	$blog = Blog::findOne($blog_id);
	// stash() lets you store stuff for later use (NOT a cache)
	stash('blog', $blog);
});

// here, we have :blog_id in the route, so our preloader gets run
get('/blogs/:blog_id', function ($blog_id) {
	// pick up what we got from the stash
	$blog = stash('blog');
	render('blogs/show', array('blog' => $blog);
});
?>
```

### Conditions
Conditions are basically helper functions. I adopted the name 'conditions' so as to encourage you to use it at the start of your handlers.

```php
<?php
// require that users are signed in
condition('signed_in', function () {
  redirect(403, '/403-forbidden', !stash('user'));
});

// require a valid token when accessing a page
get('/admin', function () {
  condition('signed_in');
  render('admin');
});
?>
```
*NOTE:* Because of the way conditions are defined, conditions can't have anonymous functions as their first parameter.

### Middleware
If you have wind up routines that need to be done before handling the request, you can queue them up using the `middleware()` function.

```php
<?php
// create a db connection and stash it
middleware(function () {
	$db = create_connection();
	stash('db', $db);
});

// assume that the db connection was stash()ed
get('/list', function () {
	$db = stash('db');
	// do stuff with the DB
});
?>
```

### Caching via APC
If you have `apc.so` enabled, you can make use of dispatch's simple caching functions.

```php
<?php
// fetch something from the cache (ttl for the cache is 60, based on last parameter)
$data = cache('users', function () {
  // this function is called as a loader if apc doesn't have 'users' in the cache,
  // whatever it returns gets stored into apc and mapped to the 'users' key
  return array('sheryl', 'addie', 'jaydee');
}, 60);

// invalidate our cached keys (users, products, news)
cache_invalidate('users', 'products', 'news');
```

### Configurations
You can make use of ini files for configuration by doing something like `config('source', 'myconfig.ini')`.
This lets you put configuration settings in ini files instead of making `config()` calls in your code.

```php
<?php
// load a config.ini file
config('source', 'my-settings.ini');

// set a different folder for the views
config('views.root', __DIR__.'/myviews');

// get the encryption secret
$secret = config('cookies.secret');
?>
```

### Utility Functions
There are a lot of other useful routines in the library. Documentation is still lacking but they're very small and easy to figure out. Read the source for now.

```php
<?php
// store a config and get it
config('views.root', './views');
config('views.root'); // returns './views'

// stash a var and get it (useful for moving stuff between scopes)
stash('user', $user);
stash('user'); // returns stored $user var

// redirect with a status code
redirect(302, '/index');

// redirect if a condition is met
redirect(403, '/users', !$authenticated);

// redirect only if func is satisfied
redirect('/admin', function () use ($auth) { return !!$auth; });

// redirect only if func is satisfied, and with a diff code
redirect(301, '/admin', function () use ($auth) { return !!$auth; });

// send a http error code and print out a message
error(403, 'Forbidden');

// get the current HTTP method or check the current method
method(); // GET, POST, PUT, DELETE
method('POST'); // true if POST request, false otherwise

// client's IP
client_ip();

// get something or a hash from a hash
$name = from($_POST, 'name');
$user = from($_POST, array('username', 'email', 'password'));

// escape a string
_h('Marley & Me');

// url encode
_u('http://noodlehaus.github.com/dispatch');

// load a partial using some file and locals
$html = partial('users/profile', array('user' => $user));
?>
```

## LICENSE
(The MIT License)

Copyright (c) 2011 Jesus A. Domingo jesus.domingo@gmail.com

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the 'Software'), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED 'AS IS', WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
