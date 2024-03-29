@extends('master')
@section('title', 'Page link editor')

@section('header')
    @parent
    <script type="text/javascript"
            src="{{asset(  "js/tinymce/jquery.tinymce.min.js")}}"></script>
    <script type="text/javascript" src="{{asset(  "js/tinymce/tinymce.min.js") }}"></script>
    <script type="text/javascript">
        var noteId = "{{$noteId}}";
        mixpanel.track("Edition de note", {"User": "{{  Auth::user()->email }}", "note": noteId});
    </script>
    <meta name="_token" content="{!! csrf_token() !!}"/>
@stop
@section('sidebar')
    @parent


@stop
@section('content')
    @include("note.menu", ["noteId", $noteId])

    <?php
    /**
     * Created by PhpStorm.
     * User: manue_001
     * Date: 20-08-15
     * Time: 13:42
     */

    $note = \App\Note::findOrFail($noteId);

    $attributes = $note->getAttributes();

    $filename = $attributes["filename"];
    $folder_id = $attributes["folder_id"];
    $content_file = $attributes["content_file"];
    $user = Auth::user()->email;
    $mime = $attributes["mime"];
    $ext = getExtension($filename);


    /*
        $filename = $note->filename;
        $content_file = $note->content_file;
        $folder_id = $note->folder_id;
        $user = Auth::user()->email;
        $ext = getExtension($filename);
        echo $mime = $note->mime;
    */


    if(isHtml($ext, $mime))
    {

    ?>
    <form action="{{asset("note/save/link/".$noteId) }}" method="POST" name="editor_form">
        <table>
            <tr>
                <td></td>
                <td><input type="hidden" name="option" value="edit.html"/></td>
            </tr>
            <tr>
                <td></td>
                <td><input type="hidden" name="composant" value="save.html"/></td>
            </tr>
            <tr>
                <td></td>
                <td><input type="hidden" name="noteId" value="<?php echo $noteId; ?>"/>
            <tr>
                <td></td>
                <td><input type="hidden" name="mime" value="<?php echo $mime; ?>"/>
            <tr>
                <td><label for="filename">Nom de fichier</label></td>
                <td>
                    <input type="text" id="filename" name="filename" value="<?php echo $filename; ?>"/>
                </td>
            </tr>
            <tr>
                <td><label for="folder_id">Dossier</label></td>
                <td>
                    <?php
                    folder_field($folder_id, "folder_id", $user);  ?></td>
            </tr>
            <tr>
                <td><label for="text_editor">Editer le lien</label></td>
                <td><textarea rows="24" cols="80" name="content_file"
                              id="text_editor"><?php echo $content_file;
                        ?></textarea><input type="file" name="uploadMedia" id="uploadMedias" value="Upload media"/></td>
            </tr>
            <tr>
                <td></td>
                <td><input type="hidden" name="_token" value="{{ csrf_token() }}"/>
                    <input type="submit" name="sauvegarder" value="Sauvergarder"
                           class="button btn btn-primary btn-success bg-success"/></td>
            </tr>
        </table>
    </form>
    <?php
    }
    else if(isVideoStream($ext, $mime)) {?>
    <form action="{{asset("note/save/link/".$noteId) }}" method="GET">
        <input type="hidden" name="noteId" value="<?php echo $noteId; ?>"/>
        <?php
        folder_field($folder_id, "folder_id", $user);  ?>
        <input type="text" name="filename" value="<?php echo $filename; ?>"/>
        <input type="submit" name="sauvegarder" value="Sauvergarder"
               class="button btn btn-primary btn-success bg-success"/>
    </form><?php
    }
    else  if(isAudioStream($ext, $mime)) {?>
    <form action="{{asset("note/save/link/".$noteId) }}" method="GET">
        <input type="hidden" name="noteId" value="<?php echo $noteId; ?>"/>
        <?php
        folder_field($folder_id, "folder_id", $user);  ?>
        <input type="text" name="filename" value="<?php echo $filename; ?>"/>
        <input type="submit" name="sauvegarder" value="Sauvergarder"
               class="button btn btn-primary btn-success bg-success"/>
    </form>
    <?php
    }


    ?>

    @include('file/uploadform_frag', ["folderId" => $folder_id])

    <a href="{{asset(  "note/joint/new/$noteId")}}" target="NEW">Ajouter
        un fichier</a>

@stop
