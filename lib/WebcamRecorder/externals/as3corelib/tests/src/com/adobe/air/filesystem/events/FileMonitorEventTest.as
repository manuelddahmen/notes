/*
 Copyright (c) 2009, Adobe Systems Incorporated
 All rights reserved.

 Redistribution and use in source and binary forms, with or without
 modification, are permitted provided that the following conditions are
 met:

 * Redistributions of source code must retain the above copyright notice,
 this list of conditions and the following disclaimer.

 * Redistributions in binary form must reproduce the above copyright
 notice, this list of conditions and the following disclaimer in the
 documentation and/or other materials provided with the distribution.

 * Neither the name of Adobe Systems Incorporated nor the names of its
 contributors may be used to endorse or promote products derived from
 this software without specific prior written permission.

 THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS
 IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO,
 THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR
 PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR
 CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL,
 EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO,
 PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR
 PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF
 LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING
 NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
 SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

package com.adobe.air.filesystem.events {

import flexunit.framework.TestCase;

import flash.filesystem.File;

public class FileMonitorEventTest extends TestCase {
    public function FileMonitorEventTest(methodName:String = null) {
        super(methodName);
    }

    public function test_clone():void {
        var e1:FileMonitorEvent = new FileMonitorEvent(FileMonitorEvent.ADD_VOLUME);
        e1.file = new File();

        var e2:FileMonitorEvent = FileMonitorEvent(e1.clone());

        assertTrue("e1 != e2", e1 != e2);
        assertTrue("e1.cancelable == e2.cancelable", e1.cancelable == e2.cancelable);
        assertTrue("e1.bubbles == e2.bubbles", e1.bubbles == e2.bubbles);
        assertTrue("e1.type == e2.type", e1.type == e2.type);
        assertTrue("e1.file == e2.file", e1.file == e2.file);
    }
}
}