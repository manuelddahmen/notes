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

package com.adobe.air.filesystem {

import flexunit.framework.TestCase;

import flash.filesystem.File;

public class FileMonitorTest extends TestCase {
    public function FileMonitorTest(methodName:String = null) {
        super(methodName);
    }

    public function test_interval():void {
        var vm1:FileMonitor = new FileMonitor();
        assertTrue("2000 == vm1.interval", FileMonitor.DEFAULT_MONITOR_INTERVAL == vm1.interval);

        var vm2:FileMonitor = new FileMonitor(null, 3000);
        assertTrue("3000 == vm2.interval", 3000 == vm2.interval);

        var vm3:FileMonitor = new FileMonitor(null, 500);
        assertTrue("1000 == vm3.interval", 1000 == vm3.interval);

        var f:File = File.desktopDirectory;

        var vm4:FileMonitor = new FileMonitor(f);
        assertTrue("f == vm4.file", f == vm4.file);
    }

}
}