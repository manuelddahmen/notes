<!DOCTYPE html>
<html lang="en">
<?php
/**
 * Created by PhpStorm.
 * User: Manuel Dahmen
 * Licence: GNU GPL v3
 * Date: 17-03-16
 * Time: 06:44
 */
?>
<head>
    <meta charset="UTF-8">
    <title>Notes diagram</title>
</head>
<body>
<canvas id="canvasInAPerfectWorld" width="490" height="220"></canvas>
<div id="canvasDiv"></div>
<script language="javascript" type="application/javascript">
    context = document.getElementById('canvasInAPerfectWorld').getContext("2d");
    var canvasDiv = document.getElementById('canvasDiv');
    canvas = document.createElement('canvas');
    canvas.setAttribute('width', canvasWidth);
    canvas.setAttribute('height', canvasHeight);
    canvas.setAttribute('id', 'canvas');
    canvasDiv.appendChild(canvas);
    if (typeof G_vmlCanvasManager != 'undefined') {
        canvas = G_vmlCanvasManager.initElement(canvas);
    }
    context = canvas.getContext("2d");
    $('#canvas').mousedown(function (e) {
        var mouseX = e.pageX - this.offsetLeft;
        var mouseY = e.pageY - this.offsetTop;

        paint = true;
        addClick(e.pageX - this.offsetLeft, e.pageY - this.offsetTop);
        redraw();
    });
    $('#canvas').mousedown(function (e) {
        var mouseX = e.pageX - this.offsetLeft;
        var mouseY = e.pageY - this.offsetTop;

        paint = true;
        addClick(e.pageX - this.offsetLeft, e.pageY - this.offsetTop);
        redraw();
    });
    $('#canvas').mouseup(function (e) {
        paint = false;
    });
    $('#canvas').mouseleave(function (e) {
        paint = false;
    });
    var clickX = [];
    var clickY = [];
    var clickDrag = [];
    var paint;

    function addClick(x, y, dragging) {
        clickX.push(x);
        clickY.push(y);
        clickDrag.push(dragging);
    }

    var colorGreen = "#659b41";
    var colorYellow = "#ffcf33";
    var colorBrown = "#986928";

    var curColor = colorPurple;
    var clickColor = [];
    var clickSize = [];
    var curSize = "normal";

    function addClick(x, y, dragging) {
        clickX.push(x);
        clickY.push(y);
        clickDrag.push(dragging);
        if (curTool == "eraser") {
            clickColor.push("white");
        } else {
            clickColor.push(curColor);
        }
        clickColor.push(curColor);
        clickSize.push(curSize);
    }
    function redraw() {
        context.lineJoin = "round";
        for (var i = 0; i < clickX.length; i++) {
            context.beginPath();
            if (clickDrag[i] && i) {
                context.moveTo(clickX[i - 1], clickY[i - 1]);
            } else {
                context.moveTo(clickX[i] - 1, clickY[i]);
            }
            context.lineTo(clickX[i], clickY[i]);
            context.closePath();
            context.strokeStyle = clickColor[i];
            context.lineWidth = radius;
            context.stroke();
        }
        if (curTool == "crayon") {
            context.globalAlpha = 0.4;
            context.drawImage(outlineImage, drawingAreaX, drawingAreaY, drawingAreaWidth, drawingAreaHeight);
        }
    }
    context.globalAlpha = 1;
    }
    var clickTool = [];
    var curTool = "crayon";
    var outlineImage = new Image();
    function prepareCanvas() {
        outlineImage.src = "{{ asset("
        images / systeme - icone - 4272 - 128;
        ")}}";;
    }
</script>
</body>
</html>