<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ultimate JavaScript Debug</title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            margin: 20px;
            background: #f5f5f5;
        }
        .test-container { 
            background: white; 
            padding: 20px; 
            margin: 10px 0; 
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .sidebar { 
            position: fixed; 
            top: 0; 
            left: -200px; 
            width: 200px; 
            height: 100vh; 
            background: #333; 
            color: white; 
            transition: left 0.3s ease;
            padding: 20px;
            z-index: 1000;
        }
        .sidebar.active { 
            left: 0; 
        }
        button { 
            padding: 10px 15px; 
            margin: 5px; 
            cursor: pointer;
            border: none;
            border-radius: 4px;
            background: #007bff;
            color: white;
        }
        button:hover { background: #0056b3; }
        .log { 
            background: #000; 
            color: #0f0; 
            padding: 10px; 
            height: 200px; 
            overflow-y: scroll; 
            font-family: monospace;
            font-size: 12px;
            margin: 10px 0;
            border-radius: 4px;
        }
        .status { 
            padding: 5px; 
            margin: 2px 0; 
            border-radius: 3px;
        }
        .ok { background: #d4edda; color: #155724; }
        .error { background: #f8d7da; color: #721c24; }
        .warning { background: #fff3cd; color: #856404; }
        .test-box {
            border: 2px solid #ddd;
            padding: 15px;
            margin: 10px 0;
            border-radius: 5px;
        }
        .test-box.running { border-color: #ffc107; }
        .test-box.success { border-color: #28a745; }
        .test-box.failed { border-color: #dc3545; }
    </style>
</head>
<body>

<h1>🔧 Ultimate JavaScript Debug Tool</h1>

<div class="sidebar" id="testSidebar">
    <h3>Test Sidebar</h3>
    <p>Ini adalah sidebar test</p>
    <button onclick="closeSidebar()">Close</button>
</div>

<div class="test-container">
    <h2>Environment Check</h2>
    <div id="envStatus"></div>
</div>

<div class="test-container">
    <h2>JavaScript Tests</h2>
    <button onclick="runAllTests()">🚀 Run All Tests</button>
    <button onclick="clearLog()">🗑️ Clear Log</button>
    <div class="log" id="debugLog"></div>
</div>

<div class="test-container">
    <h2>Manual Sidebar Tests</h2>
    <button onclick="test1()">Test 1: Show Sidebar</button>
    <button onclick="test2()">Test 2: Hide Sidebar</button>
    <button onclick="test3()">Test 3: Toggle Sidebar</button>
    <button onclick="test4()">Test 4: Check Element</button>
    <button onclick="test5()">Test 5: Add Class Manually</button>
    <button onclick="test6()">Test 6: Remove Class Manually</button>
</div>

<div class="test-container">
    <h2>Step-by-Step Debug</h2>
    <div id="testResults"></div>
</div>

<div class="test-container">
    <h2>Browser Information</h2>
    <div id="browserInfo"></div>
</div>

<script>
// Global variables for debugging
let testCount = 0;
let passedTests = 0;
let sidebar = null;

// Logging function
function log(message, type = 'info') {
    const logDiv = document.getElementById('debugLog');
    const timestamp = new Date().toLocaleTimeString();
    const colors = {
        info: '#0f0',
        error: '#f00',
        warning: '#ff0',
        success: '#0f0'
    };
    
    logDiv.innerHTML += `<div style="color: ${colors[type]}">[${timestamp}] ${message}</div>`;
    logDiv.scrollTop = logDiv.scrollHeight;
    console.log(`[${type.toUpperCase()}] ${message}`);
}

function clearLog() {
    document.getElementById('debugLog').innerHTML = '';
    log('Log cleared', 'info');
}

// Test functions
function test1() {
    log('=== TEST 1: Show Sidebar ===', 'info');
    const sidebar = document.getElementById('testSidebar');
    if (sidebar) {
        log('Sidebar element found', 'success');
        log('Current classes: ' + sidebar.className, 'info');
        sidebar.classList.add('active');
        log('Added "active" class', 'info');
        log('New classes: ' + sidebar.className, 'info');
        
        // Check if style actually changed
        const computedStyle = window.getComputedStyle(sidebar);
        log('Computed left position: ' + computedStyle.left, 'info');
    } else {
        log('ERROR: Sidebar element not found!', 'error');
    }
}

function test2() {
    log('=== TEST 2: Hide Sidebar ===', 'info');
    const sidebar = document.getElementById('testSidebar');
    if (sidebar) {
        log('Sidebar element found', 'success');
        log('Current classes: ' + sidebar.className, 'info');
        sidebar.classList.remove('active');
        log('Removed "active" class', 'info');
        log('New classes: ' + sidebar.className, 'info');
        
        const computedStyle = window.getComputedStyle(sidebar);
        log('Computed left position: ' + computedStyle.left, 'info');
    } else {
        log('ERROR: Sidebar element not found!', 'error');
    }
}

function test3() {
    log('=== TEST 3: Toggle Sidebar ===', 'info');
    const sidebar = document.getElementById('testSidebar');
    if (sidebar) {
        log('Before toggle - classes: ' + sidebar.className, 'info');
        sidebar.classList.toggle('active');
        log('After toggle - classes: ' + sidebar.className, 'info');
        
        const computedStyle = window.getComputedStyle(sidebar);
        log('Computed left position: ' + computedStyle.left, 'info');
    } else {
        log('ERROR: Sidebar element not found!', 'error');
    }
}

function test4() {
    log('=== TEST 4: Check Element ===', 'info');
    const sidebar = document.getElementById('testSidebar');
    log('getElementById result: ' + sidebar, 'info');
    log('Element type: ' + typeof sidebar, 'info');
    
    if (sidebar) {
        log('Element tag name: ' + sidebar.tagName, 'info');
        log('Element ID: ' + sidebar.id, 'info');
        log('Element classes: ' + sidebar.className, 'info');
        log('Element innerHTML length: ' + sidebar.innerHTML.length, 'info');
        
        // Check if element is in DOM
        const isInDOM = document.body.contains(sidebar);
        log('Element is in DOM: ' + isInDOM, isInDOM ? 'success' : 'error');
        
        // Check computed styles
        const styles = window.getComputedStyle(sidebar);
        log('Position: ' + styles.position, 'info');
        log('Left: ' + styles.left, 'info');
        log('Width: ' + styles.width, 'info');
        log('Display: ' + styles.display, 'info');
        log('Visibility: ' + styles.visibility, 'info');
    }
}

function test5() {
    log('=== TEST 5: Add Class Manually ===', 'info');
    const sidebar = document.getElementById('testSidebar');
    if (sidebar) {
        // Direct class manipulation
        sidebar.className = sidebar.className + ' active';
        log('Added class via className: ' + sidebar.className, 'info');
        
        // Check if CSS is applied
        setTimeout(() => {
            const computedStyle = window.getComputedStyle(sidebar);
            log('Left position after manual class add: ' + computedStyle.left, 'info');
        }, 100);
    }
}

function test6() {
    log('=== TEST 6: Remove Class Manually ===', 'info');
    const sidebar = document.getElementById('testSidebar');
    if (sidebar) {
        sidebar.className = sidebar.className.replace('active', '').trim();
        log('Removed class via className: ' + sidebar.className, 'info');
        
        setTimeout(() => {
            const computedStyle = window.getComputedStyle(sidebar);
            log('Left position after manual class remove: ' + computedStyle.left, 'info');
        }, 100);
    }
}

function closeSidebar() {
    log('Close button clicked from inside sidebar', 'info');
    test2();
}

// Comprehensive testing
function runTest(name, testFunc) {
    testCount++;
    const testDiv = document.createElement('div');
    testDiv.className = 'test-box running';
    testDiv.innerHTML = `<strong>Test ${testCount}: ${name}</strong><div>Running...</div>`;
    document.getElementById('testResults').appendChild(testDiv);
    
    try {
        testFunc();
        testDiv.className = 'test-box success';
        testDiv.children[1].innerHTML = 'PASSED ✅';
        passedTests++;
        log(`Test ${testCount} (${name}): PASSED`, 'success');
    } catch (error) {
        testDiv.className = 'test-box failed';
        testDiv.children[1].innerHTML = `FAILED ❌: ${error.message}`;
        log(`Test ${testCount} (${name}): FAILED - ${error.message}`, 'error');
    }
}

function runAllTests() {
    log('🚀 Starting comprehensive test suite...', 'info');
    testCount = 0;
    passedTests = 0;
    document.getElementById('testResults').innerHTML = '';
    
    // Test 1: Basic JavaScript
    runTest('Basic JavaScript', () => {
        if (typeof console === 'undefined') throw new Error('Console not available');
        if (typeof document === 'undefined') throw new Error('Document not available');
        if (typeof window === 'undefined') throw new Error('Window not available');
    });
    
    // Test 2: DOM Access
    runTest('DOM Access', () => {
        const testDiv = document.createElement('div');
        if (!testDiv) throw new Error('Cannot create elements');
        document.body.appendChild(testDiv);
        document.body.removeChild(testDiv);
    });
    
    // Test 3: CSS Class Manipulation
    runTest('CSS Class Manipulation', () => {
        const testDiv = document.createElement('div');
        testDiv.classList.add('test-class');
        if (!testDiv.classList.contains('test-class')) throw new Error('classList.add failed');
        testDiv.classList.remove('test-class');
        if (testDiv.classList.contains('test-class')) throw new Error('classList.remove failed');
    });
    
    // Test 4: Sidebar Element
    runTest('Sidebar Element Exists', () => {
        sidebar = document.getElementById('testSidebar');
        if (!sidebar) throw new Error('Sidebar element not found');
    });
    
    // Test 5: CSS Transition
    runTest('CSS Transition', () => {
        if (!sidebar) throw new Error('Sidebar not available');
        const styles = window.getComputedStyle(sidebar);
        if (!styles.transition || styles.transition === 'none') {
            throw new Error('CSS transition not applied');
        }
    });
    
    // Test 6: Class Toggle
    runTest('Class Toggle Function', () => {
        if (!sidebar) throw new Error('Sidebar not available');
        const initialClasses = sidebar.className;
        sidebar.classList.toggle('active');
        const afterToggle = sidebar.className;
        if (initialClasses === afterToggle) throw new Error('Class toggle had no effect');
    });
    
    // Summary
    setTimeout(() => {
        log(`\n=== TEST SUMMARY ===`, 'info');
        log(`Total tests: ${testCount}`, 'info');
        log(`Passed: ${passedTests}`, 'success');
        log(`Failed: ${testCount - passedTests}`, passedTests === testCount ? 'success' : 'error');
        log(`Success rate: ${((passedTests / testCount) * 100).toFixed(1)}%`, 'info');
    }, 500);
}

// Environment check
function checkEnvironment() {
    const envDiv = document.getElementById('envStatus');
    let html = '';
    
    const checks = [
        ['JavaScript Enabled', typeof console !== 'undefined', 'JavaScript is working'],
        ['DOM Available', typeof document !== 'undefined', 'Document object accessible'],
        ['Window Object', typeof window !== 'undefined', 'Window object accessible'],
        ['CSS Support', typeof getComputedStyle !== 'undefined', 'CSS style computation available'],
        ['ClassList Support', document.createElement('div').classList !== undefined, 'Modern class manipulation available'],
        ['Sidebar Element', document.getElementById('testSidebar') !== null, 'Test sidebar element found']
    ];
    
    checks.forEach(([name, condition, description]) => {
        const status = condition ? 'ok' : 'error';
        const icon = condition ? '✅' : '❌';
        html += `<div class="status ${status}">${icon} <strong>${name}:</strong> ${description}</div>`;
    });
    
    envDiv.innerHTML = html;
}

// Browser info
function showBrowserInfo() {
    const infoDiv = document.getElementById('browserInfo');
    infoDiv.innerHTML = `
        <strong>User Agent:</strong> ${navigator.userAgent}<br>
        <strong>Platform:</strong> ${navigator.platform}<br>
        <strong>Language:</strong> ${navigator.language}<br>
        <strong>Online:</strong> ${navigator.onLine}<br>
        <strong>Cookies Enabled:</strong> ${navigator.cookieEnabled}<br>
        <strong>Screen Resolution:</strong> ${screen.width}x${screen.height}<br>
        <strong>Window Size:</strong> ${window.innerWidth}x${window.innerHeight}<br>
        <strong>Color Depth:</strong> ${screen.colorDepth} bits<br>
        <strong>Timezone:</strong> ${Intl.DateTimeFormat().resolvedOptions().timeZone}
    `;
}

// Initialize when page loads
window.addEventListener('load', function() {
    log('Page fully loaded', 'success');
    checkEnvironment();
    showBrowserInfo();
    log('Environment check completed', 'info');
    log('Ready for testing!', 'success');
    
    // Auto-run basic test
    setTimeout(runAllTests, 1000);
});

// Also run on DOM ready
document.addEventListener('DOMContentLoaded', function() {
    log('DOM Content Loaded', 'success');
});

// Log any errors
window.addEventListener('error', function(e) {
    log(`GLOBAL ERROR: ${e.message} at ${e.filename}:${e.lineno}`, 'error');
});

// Log initial status
log('Script loaded successfully', 'success');
</script>

</body>
</html>