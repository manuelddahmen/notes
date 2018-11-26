## Bloc-notes 2 
Pour configurer, éditer:

- config/app.php
    'hostname' => 'http://yoursite.com',
    'plus_config' =>'hostname' => 'http://yoursite.com/',
    $db_prefix = "préfixe de table";
    define( , "/directory");


### Licence
GNU GPL v3

### Current (pre) BlokNot-2.1.0 Zufrem V2

Is a webapp for file management online .
* Features added
* Future features
* Upoad files by capturing webcam data (images, video or sound) (developing in progress)
* Video capturing
* Sort folders
* Bugs
* Fixes


### b Framework ("container")
-----------------------
 (TODO check for version number) 
 * TinyMCE
 * Laravel 
 * SoundManager v2 (not working)
 * JQuery
 * ...
 
### Official Website
----------------
http://www.manudahmen.be

 
 http://www.manudahmen.be
 
 
 https://www.demoniakmachine.com/notes/login
 
 Before it was :
 
     Route::get('auth/login', ["as" => "login_form", "uses" =>
     		function()
     		{
     			return view("auth/login");
     		}]
     );
     
     Route::post('auth/login', 'Auth\AuthController@postLogin');
     Route::get('auth/logout', 'Auth\AuthController@getLogout');
     
     
     Route::post('post_images', function () {
     	require_once("../app_tinymce_file_acceptor.php");
     });
     
     
     Route::get('auth/register', ["as" => "register", "uses" =>
     	function() {
     		return view('auth\register');
     	}]);
     Route::post('auth/register', ['as' => 'register_submit', 'uses' => 'Auth\AuthController@postRegister']);
     
     Route::get('radio', ['middleware' => "auth", "uses" => function () {
     	return view('radio.index');
     }]);
 Then fill the form and login.
 
 I got an error that I ve never seen before:
 
 https://www.demoniakmachine.com/notes/auth/login
  419
 
 Sorry, your session has expired. Please refresh and try again.
 
 I try this:
 - update to 5.7.9
 - check sessions and purge the session directory.
 - generate new appkey, running artisan and composer numerous times.
 
 And I don't see the solution.
 
 I misunderstand why {{asset("auth/login")}} leads to "login" (whitout "auth") and the form post leads to "auth/login".
 
 As I read, Laravel perhaps wants Users to use the builtin login/register. How to use this feature from an ?