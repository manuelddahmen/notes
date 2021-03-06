@extends('master')
@section('title', 'Voir le fichier')
<?php
/**
 * Created by PhpStorm.
 * User: manue_001
 * Date: 20-08-15
 * Time: 13:42
 */



$note = getDBDocument($noteId);

?>
@section('header')
    @parent
    <script type="text/javascript">


        soundManager.setup({

            // location: path to SWF files, as needed (SWF file name is appended later.)

            url: '{{asset("js/soundmanagerv2/swf/") }}',

            // optional: version of SM2 flash audio API to use (8 or 9; default is 8 if omitted, OK for most use cases.)
            // flashVersion: 9,

            // use soundmanager2-nodebug-jsmin.js, or disable debug mode (enabled by default) after development/testing
            // debugMode: false,

            // good to go: the onready() callback

            onready: function () {

                // SM2 has started - now you can create and play sounds!

                var mySound = soundManager.createSound({
                    id: 'aSound' + noteId, // optional: provide your own unique id
                    url: '{{ asset('file/view/'.$noteId) }}'
                    // onload: function() { console.log('sound loaded!', this); }
                    // other options here..
                });

                mySound.play();

            },

            // optional: ontimeout() callback for handling start-up failure

            ontimeout: function () {

                // Hrmm, SM2 could not start. Missing SWF? Flash blocked? No HTML5 audio support? Show an error, etc.?
                // See the flashblock demo when you want to start getting fancy.

            }

        });


    </script>
    <script src="{{asset("js/soundmanagerv297a-20150601/script/soundmanager2-jsmin.js")}}"></script>
    <script src="{{asset("js/soundmanagerv297a-20150601/script/bar-ui.js")}}"></script>
    <script>
        function soundLoad() {
            type_html_start += "<br />Audio setup...";
            soundManager.setup({
                url: '{{asset(  "js/soundmanagerv297a-20150601/swf/")}}',
                onready: function () {
                    var mySound = soundManager.createSound({
                        id: 'aSound',
                        url: '{{ asset(  "file/view/$noteId") }}'
                    });
                    type_html_start += "<br />Start playing...";
                    mySound.play();
                    window.sm2BarPlayers[0].actions.play();
                },
                ontimeout: function () {
                    // Hrmm, SM2 could not start. Missing SWF? Flash blocked? Show an error, etc.?
                    type_html_start += "<br />Hrmm, SM2 could not start. Missing SWF? Flash blocked? Show an error, etc.?";
                }
            });
        }
    </script>
    <script type="application/javascript">
        function updateJoint() {
            $.get("{{ asset("note/joint/list/$noteId") }}",
                    function (server_response) {
                        $("#lien_liste").html(server_response);
                    });
        }
        var type_html_start = "errors";
        var text_to_load = "";
        var type_viewed = "unknown";
        function updateNote() {

            $.get("{{asset(  "file/mime-type/$noteId")}}",

                    function (server_response) {
                        if (server_response.search("image") > -1) {
                            type_html_start = "<img src='{{ asset(  "file/view/".$noteId) }}''/>";
                        } else if (server_response.search("text") > -1) {
                            type_html_start = "{{ asset( "file/view/".$noteId) }}";
                            type_viewed = "text";
                        } else if (server_response.search("directory") > -1) {
                            type_html_start = "Repertoire";
                        } else if (server_response.search("application/pdf") > -1) {
                            type_html_start = "<a href='{{asset(  "file/view/".$noteId)}}' target='_NEW'>Visualiser sur une nouvelle page</a><br/><iframe src ='{{asset("js/viewerJS")."/#".asset("file/view/$noteId")}}' width='400' height='300' allowfullscreen webkitallowfullscreen></iframe>";
                        } else if (server_response.search("videoFlow") > -1) {


                            var file_name = "http://www.ibiteria.com/{{asset("file/view/".$noteId)}}";
                            type_html_start = "<video src='<?php echo ""; ?>" + file_name + "' controls autoplay>" +
                                    "Votre navigateur n'est pas compatible avec le HTML 5, désolé." +
                                    "</video>";
                            /*
                             type_html_start = '.swf' +
                             '<OBJECT classid="clsid:D27CDB6E-AE6D-11cf-96B8-444553540000" codebase="http://download.macromedia.com/pub/shockwave/cabs/flash/swflash.cab#version=6,0,0,0″ WIDTH="320" HEIGHT="240" id="' + file_name + '" ALIGN="">' +

                             '<PARAM NAME=movie VALUE="' + file_name + '"> <PARAM NAME=quality VALUE=high> <PARAM NAME=bgcolor VALUE=#333399> <EMBED src="videoFlow.swf" quality=high bgcolor=#333399 WIDTH="320" HEIGHT="240" NAME="' + file_name + '" ALIGN="" TYPE="application/x-shockwave-flash" PLUGINSPAGE="http://www.macromedia.com/go/getflashplayer"></EMBED> </OBJECT>' +

                             'Note: Remplacez videoFlow.swf par le lien de votre fichier vidéo.' +
                             '.mp4' +
                             '<videoFlow width="320" height="240" controls>' +

                             '<source src="' + file_name + '" type="' + server_response + '">' +

                             'Votre navigateur ne supporte pas cet extension de videoFlow.' +

                             '</videoFlow>';
                             */
                        } else if (server_response.search('audio') > -1) {
                            type_html_start = "Audio";
                            soundLoad();
                        }

                        $("#note_viewer_container").html(type_html_start);


                        if (type_viewed == "text") {
                            $("#note_viewer_container").html("Loading text ...");
                            $.get(type_html_start,
                                    function (server_response) {
                                        $("#note_viewer_container").html(server_response);
                                    });
                        }

                    });

        }

        updateNote();

    </script>

@endsection

@section('menuitems')
    @parent
    @include("note.menu", ["noteId", $noteId])
@endsection

@section('content')
    @parent
    <div class="sm2-bar-ui">
        <!-- player HTML goes here -->
    </div>
    <div id="note_viewer_container" onclick="updateNote();" style="padding: 20px; border: 3px groove #24a199">

    </div>
    <div id="signature">
        <?php
        echo $note->getAttribute('username');
        ?><?php
        echo $note->getAttribute('updated_at');
        ?>
    </div>
    <div id="lien_liste" style="padding: 20px; border: 3px groove #24a199">

    </div>
    <!-- Insert in object page here is the code to play file -->

    <!-- configure SM2 for your use -->
    <a href="{{asset("note/joint/list/$noteId")}}">Liens </a>
@endsection
