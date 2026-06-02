<?php
include('../essential/backbone.php');

$username  = $_COOKIE['user_name'] ?? '';
$sessionID = $_COOKIE['sessionId'] ?? '';

$loggedIn = confirmSessionKey($username, $sessionID);
$isAdmin  = checkIsUserAdmin($username, $sessionID);

$idx = getUserID();
$valid_until = validUntil($idx);

if (!$loggedIn) {
    echo "<html><body style='background:#2a0057;color:white;text-align:center;padding-top:80px;font-family:Segoe UI;'>
        <h2>You must be logged in to access this page</h2>
        <a href='https://cyenox.com/login' style='color:#b366ff;font-size:20px;font-weight:bold;'>Click here to log in</a>
    </body></html>";
    exit;
}

if ($valid_until < time()) {
    echo "<html><body style='background:#2a0057;color:white;text-align:center;padding-top:80px;font-family:Segoe UI;'>
        <h2>You must have an active subscription to use this page</h2>
        <a href='https://cyenox.com/topup' style='color:#b366ff;font-size:20px;font-weight:bold;'>Click here to top up</a>
    </body></html>";
    exit;
}

// Load items for the Give command
$items = [];
$typesFile = __DIR__ . '/types_sorted.txt';
if (file_exists($typesFile)) {
    $items = file($typesFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    sort($items, SORT_NATURAL | SORT_FLAG_CASE);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>DayZ Admin Panel</title>
<style>
body { background:#1a002a; color:#fff; font-family:Arial,sans-serif; margin:0; }
.topbar { background:#110022; padding:15px 30px; display:flex; justify-content:space-between; align-items:center; box-shadow:0 2px 10px rgba(128,0,128,0.2); }
.topbar h1 { margin:0; color:#b366ff; font-size:20px; }
.topbar a { color:#fff; text-decoration:none; margin-left:15px; font-size:14px; }
.topbar a:hover { color:#d1a3ff; }
.wrapper { display:flex; justify-content:center; align-items:center; height:calc(100vh - 70px); }
.container { background-color:#330055; padding:25px; border-radius:8px; width:380px; box-shadow:0 0 15px rgba(180,102,255,0.4); }
h2 { text-align:center; margin-bottom:15px; color:#d1a3ff; }
label { display:block; margin-top:12px; font-size:14px; }
input { width:100%; padding:8px; margin-top:5px; border-radius:4px; border:none; background-color:#4d1a66; color:#fff; }
.cmd-buttons { margin-top:20px; display:grid; grid-template-columns:1fr 1fr; gap:10px; }
button, .donate-btn { padding:10px; border:none; border-radius:4px; background-color:#9933ff; color:#fff; font-size:14px; cursor:pointer; display:block; transition:0.2s; text-align:center; text-decoration:none; }
button:hover, .donate-btn:hover { background-color:#b366ff; }
.donate-btn { margin-top:20px; background-color:#6633ff; }
.donate-btn:hover { background-color:#804dff; }
.response { margin-top:15px; padding:10px; background-color:#33004d; border-radius:4px; font-size:13px; white-space:pre-wrap; border:1px solid #6600cc; position:relative; }
.dropdown-item { padding:4px 8px; cursor:pointer; }
.dropdown-item:hover { background-color:#6600cc; }
</style>
</head>

<body>

<div class="topbar">
    <h1>DayZ Admin Panel</h1>
    <div><a href="/">Home</a><a href="/topup">Account</a></div>
</div>

<div class="wrapper">
<div class="container">
    <h2>Server Commands</h2>

    <label>Server ID</label>
    <input id="serverID">

    <label>Target Player Username</label>
    <input id="targetCode">

    <label>Optional Message / Item / Coordinates</label>
    <div style="position:relative;">
        <input id="extraInput" placeholder="Depends on command…" autocomplete="off">
        <div id="dropdown" style="position:absolute; top:100%; left:0; width:100%; max-height:150px; overflow-y:auto; background:#4d1a66; border:1px solid #6600cc; border-radius:4px; display:none; z-index:9999;"></div>
    </div>
	<div id="mapSelectWrapper" style="display:none;">
    <label>Select Map</label>
    <select id="mapSelect" style="width:100%; padding:8px; margin-top:5px; border-radius:4px; border:none; background:#4d1a66; color:white;">
        <option value="chernarus">Chernarus (15400 x 15400)</option>
        <option value="livonia">Livonia (12800 x 12800)</option>
    </select>
</div>



    <button id="showMapBtn" style="margin-top:15px; width:100%; background:#6633ff;">Show Teleport Map</button>

    <div id="mapWrapper" style="display:none; margin-top:20px; text-align:center;">
    <h3 style="color:#d1a3ff;">Click the Map to Select Teleport Coordinates</h3>

    <img id="dayzMap" 
         src="chernarus.jpg" 
         style="width:100%; max-width:350px; cursor:crosshair; border:2px solid #6600cc; border-radius:6px;">

    <div id="mapCoords" style="margin-top:10px; font-size:14px; color:#d1a3ff;"></div>
</div>


    <div class="cmd-buttons">
        <button onclick="runCmd('notify')">Notify</button>
        <button onclick="runCmd('kick')">Kick</button>
        <button onclick="runCmd('kill')">Kill</button>
        <button onclick="runCmd('godmode_on')">Godmode ON</button>
        <button onclick="runCmd('godmode_off')">Godmode OFF</button>
        <button onclick="runCmd('global')">Global Msg</button>
        <button onclick="runCmd('teleport')">Teleport</button>
        <button onclick="runCmd('give')">Give Item</button>
        <button onclick="runCmd('blowup')">Blow Up Player</button>
        <button onclick="runCmd('player_list')">Get Player List</button>
    </div>

    <div id="responseBox" class="response"></div>
</div>

</div>

<script>
const extraInput = document.getElementById("extraInput");
const dropdown = document.getElementById("dropdown");
const serverID = document.getElementById("serverID");
const targetCode = document.getElementById("targetCode");
const itemOptions = <?php echo json_encode($items, JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_APOS); ?>;

// Remember input values
["serverID","targetCode","extraInput"].forEach(id=>{
    const el=document.getElementById(id);
    const saved=localStorage.getItem("saved_"+id);
    if(saved) el.value=saved;
    el.addEventListener("input",e=>localStorage.setItem("saved_"+id,e.target.value));
});

let giveCommandActive = false;

function showDropdown() {
    if(!giveCommandActive) { dropdown.style.display="none"; return; }
    const value = extraInput.value.toLowerCase();
    if(!value) { dropdown.style.display="none"; return; }

    const matches = itemOptions.filter(i=>i.toLowerCase().includes(value)).slice(0,50);
    dropdown.innerHTML="";
    if(matches.length===0){ dropdown.style.display="none"; return; }

    matches.forEach(item=>{
        const div=document.createElement("div");
        div.textContent=item;
        div.className="dropdown-item";
        div.addEventListener("mousedown",()=>{ extraInput.value=item; dropdown.style.display="none"; });
        div.addEventListener("mouseover",()=>div.style.backgroundColor="#6600cc");
        div.addEventListener("mouseout",()=>div.style.backgroundColor="#4d1a66");
        dropdown.appendChild(div);
    });
    dropdown.style.display="block";
}

extraInput.addEventListener("input", showDropdown);
document.addEventListener("click", e=>{ if(!dropdown.contains(e.target) && e.target!==extraInput) dropdown.style.display="none"; });

function runCmd(type){
    let cmdType="";
    let cmdContent = extraInput.value.trim();
    switch(type){
        case "notify": cmdType="notify"; giveCommandActive=false; break;
        case "kick": cmdType="kick"; cmdContent=""; giveCommandActive=false; break;
        case "kill": cmdType="kill"; cmdContent=""; giveCommandActive=false; break;
        case "godmode_on": cmdType="godmode"; cmdContent="on"; giveCommandActive=false; break;
        case "godmode_off": cmdType="godmode"; cmdContent="off"; giveCommandActive=false; break;
        case "global": cmdType="global"; giveCommandActive=false; break;
        case "player_list": cmdType="player_list"; giveCommandActive=false; break;
        case "teleport": cmdType="teleport"; giveCommandActive=false; break;
        case "give": cmdType="give"; giveCommandActive=true; break;
        case "blowup": cmdType="give"; cmdContent="ExplosionTest"; giveCommandActive=false; break;
        default: giveCommandActive=false; break;
    }
    if(!giveCommandActive) dropdown.style.display="none";
    sendCommand(cmdType, cmdContent);
}

function sendCommand(cmdType, cmdContent){
    const responseBox=document.getElementById("responseBox");
    responseBox.textContent="Sending...";
    const formData=new FormData();
    formData.append("serverID",serverID.value);
    formData.append("targetCode",targetCode.value);
    formData.append("commandType",cmdType);
    formData.append("commandContent",cmdContent);

    fetch("/dayz/submitCommandAdmin.php",{method:"POST",body:formData})
        .then(r=>r.text()).then(t=>responseBox.textContent=t)
        .catch(e=>responseBox.textContent="ERROR: "+e);
}
	
// Map configuration
const MAPS = {
    chernarus: {
        max: 15400,
        image: "chernarus.jpg"
    },
    livonia: {
        max: 12800,
        image: "livonia.jpg"
    }
};

let currentMap = "chernarus";

// Handle map selection
document.getElementById("mapSelect").addEventListener("change", function () {
    currentMap = this.value;

    const mapImg = document.getElementById("dayzMap");
    mapImg.src = MAPS[currentMap].image;

    document.getElementById("mapCoords").textContent = "";
});

// Show/hide map
document.getElementById("showMapBtn").addEventListener("click", () => {
    const map = document.getElementById("mapWrapper");
    const selector = document.getElementById("mapSelectWrapper");

    const showing = map.style.display === "none";

    map.style.display = showing ? "block" : "none";
    selector.style.display = showing ? "block" : "none";
});


// Map click → teleport coordinates
const mapImg = document.getElementById("dayzMap");
const mapCoords = document.getElementById("mapCoords");

mapImg.addEventListener("click", function (e) {
    const rect = mapImg.getBoundingClientRect();

    // Pixel position inside the image
    const px = e.clientX - rect.left;
    const py = e.clientY - rect.top;

    // Image dimensions
    const imgW = mapImg.clientWidth;
    const imgH = mapImg.clientHeight;

    // Map max coordinate (depends on selected map)
    const maxCoord = MAPS[currentMap].max;

    // Convert to world coordinates
    const worldX = (px / imgW) * maxCoord;
    const worldY = maxCoord - ((py / imgH) * maxCoord);

    const formatted = `${worldX.toFixed(2)} 100 ${worldY.toFixed(2)}`;

    // Show on screen
    mapCoords.textContent = "Selected: " + formatted;

    // Auto-fill into your existing input box
    document.getElementById("extraInput").value = formatted;

    // Save to localStorage like your other fields
    localStorage.setItem("saved_extraInput", formatted);
});


</script>

</body>
</html>
