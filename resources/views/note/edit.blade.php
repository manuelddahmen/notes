@extends('master')
@section('title', 'Editor of note')

@section("menuitems")
    @include("note.menu", ["noteId", $noteId])
@endsection

@section('content')

    <?php
    /**
     * Created by PhpStorm.
     * User: manue_001
     * Date: 20-08-15
     * Time: 13:42
     */

    //require_once(realpath(base_path("lib/bloc-notes2/composant/browser/listesItem.php")));
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


    if(isTexte($ext, $mime))
    {

    ?>
    <form action="{{asset("note/save/txt/".$noteId) }}" method="GET" name="editor_form">
        <table>
            <tr>
                <td></td>
                <td><input type="hidden" name="option" value="edit.db"/></td>
            </tr>
            <tr>
                <td></td>
                <td><input type="hidden" name="composant" value="save.db"/></td>
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
                <td><label for="text_editor">Editer la note</label></td>
                <td><textarea rows="24" cols="80" name="content_file"
                              id="text_editor">
            <?php
                        echo htmlentities($content_file);
                        ?></textarea>
                    <input type="file" name="uploadMedia" id="uploadMedias" value="Upload media"/></td>
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
    else if(isImage($ext, $mime)) {?>
    <form action="{{asset("note/save/img/".$noteId) }}" method="GET">
        <input type="hidden" name="noteId" value="<?php echo $noteId; ?>"/>
        <?php
        folder_field($folder_id, "folder_id", $user);  ?>
        <input type="text" name="filename" value="<?php echo $filename; ?>"/>
        <input type="submit" name="sauvegarder" value="Sauvergarder" class="button btn btn-primary btn-success bg-success"/>
    </form><?php
    }
    else {?>
    <form action="{{asset("note/save/other/".$noteId) }}" method="GET">
        <input type="hidden" name="noteId" value="<?php echo $noteId; ?>"/>
        <?php
        folder_field($folder_id, "folder_id", $user);  ?>
        <input type="text" name="filename" value="<?php echo $filename; ?>"/>
        <input type="submit" name="sauvegarder" value="Sauvergarder" class="button btn btn-primary btn-success bg-success"/>
    </form>
    <?php
    }


    ?>

@include('file/uploadform_frag', ["folderId" => $folder_id])

    <a href="{{asset(  "note/joint/new/$noteId")}}" target="NEW">Ajouter
        un fichier</a>
    <script type="text/javascript" src="{{ asset("js/tinyMCE.init.js") }}"></script>

@endsection