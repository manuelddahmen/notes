<!-- resources/views/auth/login.blade.php -->
@extends('master')
@section('content')

    <div id="login_form">
        <?php if (Auth::check()) {
            echo "Vous &ecirc; - " . (Auth::user()->email) . " -  connect&eacute;(e)s.";
        }
        ?>
        <ul>
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
        <form method="POST" action="{{ route("login_form") }}">
            {!! csrf_field() !!}
<table>
            <tr>
            <td>Email</td>
            <td><input type="email" name="email" value="{{ old('email') }}"></td>
            </tr>

            <tr>
                <td>Password</td>
                <td><input type="password" name="password" id="password"></td>
            </tr>

            <tr>
                <td>Remember Me</td>
                <td><input type="checkbox" name="remember"></td>
            </tr>

            <tr>
                <td></td>
                <td>
                    <button type="submit">Login</button>
                </td>
            </tr>
            <tr>
                <td><a href="{{ route("register") }}">S'enregistrer</a></td>
                <td><a href="{{ asset('email/lost') }}">Mot de passe oubli&eacute;</a></td>
            </tr>
</table>
        </form>
    </div>
@stop