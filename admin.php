<?php
include('../essential/backbone.php');

$username  = $_COOKIE['user_name'] ?? '';
$sessionID = $_COOKIE['sessionId'] ?? '';

$loggedIn = confirmSessionKey($username, $sessionID);
$isAdmin  = checkIsUserAdmin($username, $sessionID);

$idx = getUserID();
$valid_until = validUntil($idx);

if (!$loggedIn) {
    echo "<html><body style='background:#150021;color:white;text-align:center;padding-top:80px;font-family:Segoe UI;'>
        <h2>You must be logged in to access this page</h2>
        <a href='https://cyenox.com/login' style='color:#c44dff;font-size:20px;font-weight:bold;'>Click here to log in</a>
    </body></html>";
    exit;
}

if ($valid_until < time()) {
    echo "<html><body style='background:#150021;color:white;text-align:center;padding-top:80px;font-family:Segoe UI;'>
        <h2>You must have an active subscription to use this page</h2>
        <a href='https://cyenox.com/topup' style='color:#c44dff;font-size:20px;font-weight:bold;'>Click here to top up</a>
    </body></html>";
    exit;
}

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
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>DayZ Admin Panel</title>

<style>
* {
    box-sizing: border-box;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
}

body {
    margin: 0;
    min-height: 100vh;
    display: flex;
    justify-content: center;
    align-items: center;
    background: radial-gradient(circle at top, #3a005a 0%, #210033 60%, #150021 100%);
    color: #fff;
}

.container {
    width: 94%;
    max-width: 440px;
    background: linear-gradient(180deg, #4a0072 0%, #3a005f 100%);
    border-radius: 24px;
    padding: 28px 22px;
    box-shadow:
        0 0 60px rgba(180, 0, 255, 0.35),
        inset 0 0 40px rgba(255, 255, 255, 0.03);
}

h1 {
    text-align: center;
    font-size: 24px;
    margin-bottom: 24px;
}

label {
    font-size: 14px;
    margin-bottom: 6px;
    display: block;
}

input, select {
    width: 100%;
    padding: 14px;
    margin-bottom: 18px;
    border-radius: 10px;
    border: none;
    background: #5a0088;
    color: white;
    font-size: 14px;
    box-shadow: inset 0 0 10px rgba(0,0,0,0.25);
}

.button-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}

.btn {
    padding: 14px 0;
    border-radius: 12px;
    border: none;
    background: linear-gradient(90deg, #8f00ff, #c44dff);
    color: white;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    box-shadow: 0 0 18px rgba(190, 0, 255, 0.4);
}

.btn:active {
    transform: scale(0.97);
    opacity: 0.85;
}

.full-span {
    grid-column: span 2;
}

.response {
    margin-top: 18px;
    padding: 14px;
    border-radius: 12px;
    border: 1px solid #c000ff;
    background: rgba(160, 0, 255, 0.12);
    font-size: 13px;
    white-space: pre-wrap;
}

/* Map */
#mapWrapper {
    margin-top: 20px;
    display: none;
}

#dayzMap {
    width: 100%;
    border-radius: 14px;
    border: 2px solid #c000ff;
    cursor: crosshair;
}

/* Modal */
.modal {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.65);
    display: none;
    justify-content: center;
    align-items: center;
    backdrop-filter: blur(6px);
    z-index: 999;
}

.modal-content {
    width: 90%;
    max-width: 360px;
    background: linear-gradient(180deg, #4a0072, #350050);
    border-radius: 20px;
    padding: 20px;
    box-shadow: 0 0 40px rgba(180, 0, 255, 0.5);
}

.item-list {
    max-height: 200px;
    overflow-y: auto;
}

.item {
    padding: 10px;
    border-radius: 8px;
    margin-bottom: 8px;
    background: linear-gradient(90deg, #8f00ff, #c44dff);
    cursor: pointer;
    font-size: 14px;
}
	
#mapContainer {
    overflow: hidden;
    border-radius: 14px;
    border: 2px solid #c000ff;
    cursor: grab;
}

#mapContainer:active {
    cursor: grabbing;
}

#dayzMap {
    width: 100%;
    display: block;
}

</style>
</head>
<script src="https://unpkg.com/@panzoom/panzoom/dist/panzoom.min.js"></script>

<body>

<div class="container">
    <h1>DayZ Admin Panel</h1>

    <label>Server ID</label>
    <input id="serverID">

    <label>Affected Player Username</label>
    <input id="targetCode">

    <label>Optional Message / Coordinates / Destination Player</label>
    <input id="extraInput" placeholder="Depends on command...">

    <div class="button-grid">
        <button class="btn" onclick="runCmd('notify')">Notify</button>
        <button class="btn" onclick="runCmd('kick')">Kick</button>
        <button class="btn" onclick="runCmd('kill')">Kill</button>
        <button class="btn" onclick="runCmd('godmode_on')">Godmode ON</button>
        <button class="btn" onclick="runCmd('godmode_off')">Godmode OFF</button>
        <button class="btn" onclick="runCmd('global')">Global Msg</button>
        <button class="btn" onclick="runCmd('teleport')">Teleport</button>
        <button class="btn" onclick="openItemModal()">Give Item</button>
        <button class="btn" onclick="runCmd('blowup')">Blow Up Player</button>
		<button class="btn" onclick="runCmd('player_list')">Get Player List</button>
		<button class="btn" onclick="openSafeZoneModal()">Add Safe Zone</button>
		<button class="btn" onclick="openManageZonesModal()">Manage Safe Zones</button>


    </div>

    <button class="btn full-span" style="margin-top:15px;" onclick="toggleMap()">Teleport Map</button>

    <div id="mapWrapper">
        <select id="mapSelect">
            <option value="chernarus">Chernarus (15400 x 15400)</option>
            <option value="livonia">Livonia (12800 x 12800)</option>
        </select>
        <div id="mapContainer">
        <img id="dayzMap" src="chernarus.jpg">
        </div>

        <div id="mapCoords"></div>
    </div>

    <div id="responseBox" class="response"></div>
</div>

<!-- Item Modal -->
<!-- Item Modal -->
<div class="modal" id="itemModal">
    <div class="modal-content">
        <h2>Select Item</h2>

        <input type="text" id="itemSearch" placeholder="Search item..." oninput="filterItems()">

        <div class="item-list" id="itemList"></div>

        <hr style="margin:15px 0; border-color:#c000ff;">

        <label>Or Enter Exact Item Name</label>
        <input type="text" id="customItemInput" placeholder="Exact classname...">

        <label>Quantity</label>
        <input type="number" id="itemQuantityInput" value="1" min="1">


        <button class="btn full-span" onclick="giveCustomItem()">Give Exact Item</button>
        <button class="btn full-span" onclick="closeItemModal()">Cancel</button>
    </div>
</div>
	
<!-- Safe Zone Modal -->
<div class="modal" id="safeZoneModal">
    <div class="modal-content">
        <h2>Create Safe Zone</h2>

        <label>Zone Name</label>
        <input type="text" id="zoneNameInput" placeholder="SafeZone1">

        <label>Radius</label>
        <input type="number" id="zoneRadiusInput" placeholder="200">

        <label>Center Coordinates</label>
        <input type="text" id="zoneCoordsInput" placeholder="Click map or enter manually">

        <button class="btn full-span" onclick="createSafeZone()">Create Zone</button>
        <button class="btn full-span" onclick="closeSafeZoneModal()">Cancel</button>
    </div>
</div>
<!-- Manage Safe Zones Modal -->
<div class="modal" id="manageZonesModal">
    <div class="modal-content">
        <h2>Manage Safe Zones</h2>

        <div id="zonesList" style="max-height:300px; overflow-y:auto;"></div>

        <button class="btn full-span" onclick="closeManageZonesModal()">Close</button>
    </div>
</div>




<script>
const itemOptions = <?php echo json_encode($items); ?>;
const responseBox = document.getElementById("responseBox");

["serverID","targetCode","extraInput"].forEach(id=>{
    const el=document.getElementById(id);
    const saved=localStorage.getItem("saved_"+id);
    if(saved) el.value=saved;
    el.addEventListener("input",e=>localStorage.setItem("saved_"+id,e.target.value));
});

function runCmd(type){
    let cmdType="";
    let cmdContent=document.getElementById("extraInput").value.trim();

    switch(type){
        case "notify": cmdType="notify"; break;
        case "kick": cmdType="kick"; cmdContent=""; break;
        case "kill": cmdType="kill"; cmdContent=""; break;
        case "godmode_on": cmdType="godmode"; cmdContent="on"; break;
        case "godmode_off": cmdType="godmode"; cmdContent="off"; break;
        case "global": cmdType="global"; break;
        case "teleport": cmdType="teleport"; break;
		case "player_list": cmdType="player_list"; break;
        case "blowup": cmdType="give"; cmdContent="ExplosionTest"; break;
		case "give": cmdType="give"; break;
        default: break;
    }

    sendCommand(cmdType, cmdContent);
}

function sendCommand(cmdType, cmdContent){
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
function openSafeZoneModal(){
    document.getElementById("safeZoneModal").style.display="flex";
}

function closeSafeZoneModal(){
    document.getElementById("safeZoneModal").style.display="none";
}

function openManageZonesModal(){
    document.getElementById("manageZonesModal").style.display="flex";
    loadSafeZones();
}

function closeManageZonesModal(){
    document.getElementById("manageZonesModal").style.display="none";
}



/* Item Modal */
function openItemModal(){
    document.getElementById("itemModal").style.display="flex";
    renderItems(itemOptions);
}
function closeItemModal(){
    document.getElementById("itemModal").style.display="none";
}
function filterItems(){
    const q=document.getElementById("itemSearch").value.toLowerCase();
    renderItems(itemOptions.filter(i=>i.toLowerCase().includes(q)));
}
function renderItems(list){
    const container=document.getElementById("itemList");
    container.innerHTML="";
    list.slice(0,100).forEach(item=>{
        const div=document.createElement("div");
        div.className="item";
        div.innerText=item;
        div.onclick=()=>{
    const qty = parseInt(document.getElementById("itemQuantityInput").value) || 1;
    const finalValue = item + ":" + qty;

    document.getElementById("extraInput").value = finalValue;
    closeItemModal();
    runCmd("give");
};

        container.appendChild(div);
    });
}

/* Map */
const MAPS={
    chernarus:{max:15360,image:"chernarus.jpg"},
    livonia:{max:12800,image:"livonia.jpg"}
};
let currentMap="chernarus";

function toggleMap(){
    const w=document.getElementById("mapWrapper");
	const isHidden = window.getComputedStyle(w).display === "none";
    w.style.display = isHidden ? "block" : "none";
}

document.getElementById("mapSelect").addEventListener("change",function(){
    currentMap=this.value;
    document.getElementById("dayzMap").src=MAPS[currentMap].image;
});

document.getElementById("dayzMap").addEventListener("click", function(e){

    const rect = this.getBoundingClientRect();

    // Position inside the visible (transformed) image
    const px = e.clientX - rect.left;
    const py = e.clientY - rect.top;

    // Convert to percentage of visible image
    const percentX = px / rect.width;
    const percentY = py / rect.height;

    const max = MAPS[currentMap].max;

    const worldX = percentX * max;
    const worldY = (1 - percentY) * max;

    // Clamp values (prevents tiny negative/overflow rounding)
    const clampedX = Math.max(0, Math.min(max, worldX));
    const clampedY = Math.max(0, Math.min(max, worldY));

    const formatted = `${clampedX.toFixed(2)} 100 ${clampedY.toFixed(2)}`;

    document.getElementById("mapCoords").textContent = "Selected: " + formatted;
    document.getElementById("extraInput").value = formatted;
	document.getElementById("zoneCoordsInput").value = formatted;

});
	
function giveCustomItem(){

    const custom = document.getElementById("customItemInput").value.trim();
    const qty = parseInt(document.getElementById("itemQuantityInput").value) || 1;

    if(!custom){
        alert("Enter an item name.");
        return;
    }

    const finalValue = custom + ":" + qty;

    document.getElementById("extraInput").value = finalValue;

    closeItemModal();
    runCmd("give");
}

	
function createSafeZone(){

    const zoneName = document.getElementById("zoneNameInput").value.trim();
    const radius = document.getElementById("zoneRadiusInput").value.trim();
    const coords = document.getElementById("zoneCoordsInput").value.trim();

    if(!zoneName || !radius || !coords){
        alert("Please fill all fields.");
        return;
    }

    responseBox.textContent = "Creating Safe Zone...";

    const formData = new FormData();
    formData.append("serverID", serverID.value);
    formData.append("zoneName", zoneName);
    formData.append("radius", radius);
    formData.append("coords", coords);

    fetch("/dayz/submitSafeZone.php", {
        method: "POST",
        body: formData
    })
    .then(r=>r.text())
    .then(t=>{
        responseBox.textContent = t;
        closeSafeZoneModal();
    })
    .catch(e=>{
        responseBox.textContent = "ERROR: " + e;
    });
}

function loadSafeZones(){

    const listDiv = document.getElementById("zonesList");
    listDiv.innerHTML = "Loading...";

    fetch("/dayz/getSafeZones.php?serverID=" + serverID.value)
    .then(r=>r.text())
    .then(data=>{

        if(data.trim() === ""){
            listDiv.innerHTML = "No Safe Zones found.";
            return;
        }

        const lines = data.trim().split("\n");

        let html = "";

        lines.forEach(line=>{

            const parts = line.split(":");

            const id = parts[0];
            const name = parts[1];
            const x = parts[2];
            const z = parts[3];
            const radius = parts[4];

            html += `
                <div style="margin-bottom:10px; padding:10px; background:#222; border-radius:6px;">
                    <strong>${name}</strong><br>
                    X:${x} Z:${z} R:${radius}<br>
                    <button class="btn" onclick="deleteSafeZone(${id})">Delete</button>
                </div>
            `;
        });

        listDiv.innerHTML = html;
    });
}

function deleteSafeZone(zoneID){

    if(!confirm("Are you sure you want to delete this Safe Zone?"))
        return;

    const formData = new FormData();
    formData.append("serverID", serverID.value);
    formData.append("zoneID", zoneID);

    fetch("/dayz/deleteSafeZone.php", {
        method: "POST",
        body: formData
    })
    .then(r=>r.text())
    .then(t=>{
        responseBox.textContent = t;
        loadSafeZones(); // refresh list
    })
    .catch(e=>{
        responseBox.textContent = "ERROR: " + e;
    });
}





	
const mapElement = document.getElementById("dayzMap");
const panzoom = Panzoom(mapElement, {
    maxScale: 5,
    minScale: 1,
    contain: "outside"
});

mapElement.parentElement.addEventListener('wheel', panzoom.zoomWithWheel);

</script>

</body>
</html>
