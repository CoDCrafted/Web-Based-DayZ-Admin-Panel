const bool ADMIN_LOG_ENABLED = true;

void AdminLog(string message)
{
	if (ADMIN_LOG_ENABLED)
		Print(message);
}

class SafeZoneTrigger
{
	string m_Name;
	vector m_Center;
	float m_Radius;
	ref array<vector> m_Points = new array<vector>();
	bool m_IsPolygon = false;
	bool m_IsAdminZone = false;
	vector m_AdminTeleportOut;

	void SafeZoneTrigger(string name, vector center, float radius)
	{
		m_Name = name;
		m_Center = center;
		m_Radius = radius;
		m_IsPolygon = false;
	}

	void SetPolygon(array<vector> points)
	{
		m_Points.Clear();
		for (int i = 0; i < points.Count(); i++)
			m_Points.Insert(points[i]);
		m_IsPolygon = true;
	}

	void SetAdminZone(vector teleportOut)
	{
		m_IsAdminZone = true;
		m_AdminTeleportOut = teleportOut;
	}

	string GetZoneName()
	{
		return m_Name;
	}

	vector GetCenter()
	{
		return m_Center;
	}

	float GetRadius()
	{
		return m_Radius;
	}

	bool Contains(vector pos)
	{
		if (!m_IsPolygon)
			return vector.Distance(pos, m_Center) <= m_Radius;

		int count = m_Points.Count();
		if (count < 3)
			return false;

		bool inside = false;
		float x = pos[0];
		float z = pos[2];
		int j = count - 1;
		for (int i = 0; i < count; i++)
		{
			float xi = m_Points[i][0];
			float zi = m_Points[i][2];
			float xj = m_Points[j][0];
			float zj = m_Points[j][2];
			bool intersect = ((zi > z) != (zj > z)) && (x < (xj - xi) * (z - zi) / (zj - zi + 0.00001) + xi);
			if (intersect)
				inside = !inside;
			j = i;
		}
		return inside;
	}

	bool IsAdminZone()
	{
		return m_IsAdminZone;
	}

	vector GetAdminTeleportOut()
	{
		return m_AdminTeleportOut;
	}
}

class ZombieSpawnZone
{
	string m_Name;
	vector m_Center;
	float m_Radius;
	int m_MinCount;
	int m_MaxCount;
	ref array<vector> m_Points = new array<vector>();

	void ZombieSpawnZone(string name, vector center, float radius, int minCount, int maxCount)
	{
		m_Name = name;
		m_Center = center;
		m_Radius = radius;
		m_MinCount = minCount;
		m_MaxCount = maxCount;
	}

	void SetPoints(array<vector> points)
	{
		m_Points.Clear();
		for (int i = 0; i < points.Count(); i++)
			m_Points.Insert(points[i]);
	}

	vector GetSpawnPoint()
	{
		if (m_Points.Count() > 0)
			return m_Points.GetRandomElement();
		return GetRandomPointInRadius();
	}

	vector GetRandomPointInRadius()
	{
		float angle = Math.RandomFloat(0, 6.283185);
		float dist = Math.Sqrt(Math.RandomFloat(0, 1)) * m_Radius;
		float x = m_Center[0] + Math.Cos(angle) * dist;
		float z = m_Center[2] + Math.Sin(angle) * dist;
		float y = GetGame().SurfaceY(x, z);
		return Vector(x, y, z);
	}
}

class CustomMission;

class CommandsHttpCallback: RestCallback
{
	CustomMission m_Mission;

	void CommandsHttpCallback(CustomMission mission)
	{
		m_Mission = mission;
	}

	override void OnError(int errorCode)
	{
		if (m_Mission)
			m_Mission.OnCommandsHttpError(errorCode);
	}

	override void OnTimeout()
	{
		if (m_Mission)
			m_Mission.OnCommandsHttpTimeout();
	}

	override void OnSuccess(string data, int dataSize)
	{
		if (m_Mission)
			m_Mission.OnCommandsHttpSuccess(data);
	}
}

class PushCodesHttpCallback : RestCallback
{
    CustomMission m_Mission;

    void PushCodesHttpCallback(CustomMission mission)
    {
        m_Mission = mission;
    }

    override void OnSuccess(string data, int dataSize)
    {
        if (m_Mission)
            m_Mission.OnPushCodesSuccess(data);
    }

    override void OnError(int errorCode)
    {
        if (m_Mission)
            m_Mission.OnPushCodesError(errorCode);
    }

    override void OnTimeout()
    {
        if (m_Mission)
            m_Mission.OnPushCodesTimeout();
    }
}


class CustomMission: MissionServer
{
	static const int COMMANDS_INTERVAL_MS = 5000;
	static const string COMMANDS_HTTP_BASE_URL = "https://cyenox.com";
	static const string SERVER_ID = "VUE123";
	static const string RCON_CFG_PATH = "$profile:serverDZ.cfg";
	static const string COMMANDS_HTTP_PATH        = "commands.php";
	static const string ADMINLIST_HTTP_PATH       = "admins.php";
	static const string MODLIST_HTTP_PATH         = "mods.php";
	static const string ZONES_HTTP_PATH            = "zones.php";
	static const string SPAWN_HTTP_PATH            = "spawns.php";
	static const string ZED_SPAWN_LIST_HTTP_PATH   = "list.php";

	static const int COMMANDS_DEDUP_MS = 600000;
	static const int ACCESSLIST_RELOAD_INTERVAL_MS = 60000;
	static const int ZONES_RELOAD_INTERVAL_MS = 60000;
	static const int ZONES_CHECK_INTERVAL_MS = 2500;
	static const float ZONES_MIN_MOVE_SQ = 4.0;
	static const int ZED_SPAWN_INTERVAL_MS = 5000;
	static const int ZED_SPAWN_RELOAD_INTERVAL_MS = 60000;
	static const int LOGOUT_COOLDOWN_MS = 15000; // 15 seconds
	static const float ZED_SPAWN_PLAYER_RADIUS = 150.0;
	static const string ACCESS_LOG_PREFIX = "[AccessList] ";
	static const string COMMANDS_LOG_PREFIX = "[Commands] ";
	static const string ZONES_LOG_PREFIX = "[SafeZone] ";
	static const string ZED_LOG_PREFIX = "[ZedSpawn] ";

	ref TStringMap m_AdminIds = new TStringMap;
	ref TStringMap m_ModIds = new TStringMap;
	ref array<ref SafeZoneTrigger> m_SafeZones = new array<ref SafeZoneTrigger>();
	ref array<ref ZombieSpawnZone> m_ZombieSpawnZones = new array<ref ZombieSpawnZone>();
	ref map<string, int> m_PlayerSafeCounts = new map<string, int>();
	ref map<string, string> m_PlayerZoneName = new map<string, string>();
	ref map<string, vector> m_PlayerLastPos = new map<string, vector>();
	ref map<string, bool> m_ZombieRefillByZone = new map<string, bool>();
	ref map<string, ref array<ZombieBase>> m_ZombieTrackedByZone = new map<string, ref array<ZombieBase>>();
	ref map<string, int> m_HandledCommandIds = new map<string, int>();
	ref map<string, string> m_CodeToUID = new map<string, string>();
	ref map<string, string> m_UIDToCode = new map<string, string>();
	ref map<string, string> m_UIDToName = new map<string, string>();
	ref map<string, int> m_LastLogoutAttempt = new map<string, int>();
	ref map<string, bool> m_LogoutDelayActive = new map<string, bool>();




	bool m_CommandsRequestInFlight = false;
	bool m_ServerDzLogged = false;
	ref TStringArray m_ZombieSpawnClasses = {
		"ZmbM_FarmerFat_Beige",
		"ZmbF_CitizenANormal_Beige",
		"ZmbM_CitizenASkinny_Grey",
		"ZmbM_JoggerSkinny_Red",
		"ZmbF_JoggerSkinny_Green",
		"ZmbM_PolicemanFat"
	};

	void SetRandomHealth(EntityAI itemEnt)
	{
		if ( itemEnt )
		{
			float rndHlt = Math.RandomFloat( 0.45, 0.65 );
			itemEnt.SetHealth01( "", "", rndHlt );
		}
	}

	override void OnInit()
	{
		super.OnInit();
		EnsureConfigFiles();
		LogServerDzFileAsync();
		InitAccessLists();
		InitCommandsWatcher();
		InitSafeZones();
		InitZombieSpawnZones();
		//InitDynamicDoor();

		GetGame().GetCallQueue(CALL_CATEGORY_SYSTEM).CallLater(PushPlayerCodes, 5000, true);
	}

	override PlayerBase CreateCharacter(PlayerIdentity identity, vector pos, ParamsReadContext ctx, string characterName)
	{
		vector spawnPos = pos;
		if (identity)
			ApplySpawnOverride(identity.GetId(), spawnPos);
		Entity playerEnt;
		playerEnt = GetGame().CreatePlayer( identity, characterName, spawnPos, 0, "NONE" );
		Class.CastTo( m_player, playerEnt );

		GetGame().SelectPlayer( identity, m_player );

		return m_player;
	}

	override void StartingEquipSetup(PlayerBase player, bool clothesChosen)
	{
		EntityAI itemClothing;
		EntityAI itemEnt;

		itemClothing = player.FindAttachmentBySlotName( "Body" );
		if ( itemClothing )
		{
			SetRandomHealth( itemClothing );
			
			itemEnt = itemClothing.GetInventory().CreateInInventory( "BandageDressing" );
			player.SetQuickBarEntityShortcut(itemEnt, 2);

			string chemlightArray[] = { "Chemlight_White", "Chemlight_Yellow", "Chemlight_Green", "Chemlight_Red" };
			int rndIndex = Math.RandomInt( 0, 4 );
			itemEnt = itemClothing.GetInventory().CreateInInventory( chemlightArray[rndIndex] );
			player.SetQuickBarEntityShortcut(itemEnt, 1);
			SetRandomHealth( itemEnt );
		}
		
		itemClothing = player.FindAttachmentBySlotName( "Legs" );
		if ( itemClothing )
			SetRandomHealth( itemClothing );
		
		itemClothing = player.FindAttachmentBySlotName( "Feet" );
	}

	override void InvokeOnConnect(PlayerBase player, PlayerIdentity identity)
	{
    	super.InvokeOnConnect(player, identity);

    	if (!identity)
        	return;

    	string uid = identity.GetId();
    	if (uid == "")
        	return;

    	// 🔥 FIX: Only assign a code if the player does NOT already have one
    	if (m_UIDToCode.Contains(uid))
        	return;

    	string code = GeneratePlayerCode();
    	string name = identity.GetName();

    	m_CodeToUID.Set(code, uid);
    	m_UIDToCode.Set(uid, code);
    	m_UIDToName.Set(uid, name);

    	NotificationSystem.SendNotificationToPlayerExtended(player, 15.0, "Your panel code is: " + code, "Visit cyenox.com/dayz to try out the demo.\nServer ID is VUE123", "set:dayz_inventory image:book");

    	AdminLog("[CodeAssign] " + identity.GetName() + " -> " + code);
	}


	override void InvokeOnDisconnect(PlayerBase player)
	{
    	super.InvokeOnDisconnect(player);

    	if (!player)
        	return;

    	PlayerIdentity identity = player.GetIdentity();
    	if (!identity)
        	return;

    	string uid = identity.GetId();
    	if (uid == "")
       	 return;

    // 🔥 CLEAR logout state on real disconnect
    	m_LogoutDelayActive.Remove(uid);
    	m_LastLogoutAttempt.Remove(uid);

    	DeletePlayerCodeFromServer(uid);

    	if (m_UIDToCode.Contains(uid))
    	{
        	string code = m_UIDToCode.Get(uid);
        	m_UIDToCode.Remove(uid);
        	m_CodeToUID.Remove(code);
    	}

    	if (m_UIDToName.Contains(uid))
        	m_UIDToName.Remove(uid);

    	AdminLog("[CodeDelete] " + identity.GetName() + " (" + uid + ")");
	}

	override void HandleBody(PlayerBase player)
	{
		if (player.IsAlive())
		{
			if (ShouldPlayerBeKilled(player))
			{
				PluginAdminLog adm = PluginAdminLog.Cast(GetPlugin(PluginAdminLog));
				adm.PlayerKilledByDisconnect(player);
				
				//player.SetHealth("", "", 0.0);
			}
			else
			{
				//player.Delete();// remove the body
			}
		}
	}




	void InitCommandsWatcher()
	{
		EnsureConfigDir();
		AdminLog(COMMANDS_LOG_PREFIX + "Polling: " + COMMANDS_HTTP_BASE_URL + BuildServerPath(COMMANDS_HTTP_PATH) + " every " + COMMANDS_INTERVAL_MS + "ms");
		GetGame().GetCallQueue(CALL_CATEGORY_SYSTEM).CallLater(this.CheckCommands, COMMANDS_INTERVAL_MS, true);
	}

	void InitSafeZones()
	{
		EnsureConfigDir();
		AdminLog(ZONES_LOG_PREFIX + "Initial load via HTTP: " + COMMANDS_HTTP_BASE_URL + BuildServerPath(ZONES_HTTP_PATH));
		ReloadSafeZones();
		AdminLog(ZONES_LOG_PREFIX + "Checking players every " + ZONES_CHECK_INTERVAL_MS + "ms");
		GetGame().GetCallQueue(CALL_CATEGORY_SYSTEM).CallLater(this.CheckSafeZones, ZONES_CHECK_INTERVAL_MS, true);
		AdminLog(ZONES_LOG_PREFIX + "Reloading zones every " + ZONES_RELOAD_INTERVAL_MS + "ms");
		GetGame().GetCallQueue(CALL_CATEGORY_SYSTEM).CallLater(this.ReloadSafeZones, ZONES_RELOAD_INTERVAL_MS, true);
	}

	void InitZombieSpawnZones()
	{
		EnsureConfigDir();
		AdminLog(ZED_LOG_PREFIX + "Listing spawn_*.txt via HTTP: " + COMMANDS_HTTP_BASE_URL + BuildServerPath(ZED_SPAWN_LIST_HTTP_PATH));
		LoadZombieSpawnZones();
		ProcessZombieSpawnZones();
		GetGame().GetCallQueue(CALL_CATEGORY_SYSTEM).CallLater(this.ProcessZombieSpawnZones, ZED_SPAWN_INTERVAL_MS, true);
		GetGame().GetCallQueue(CALL_CATEGORY_SYSTEM).CallLater(this.LoadZombieSpawnZones, ZED_SPAWN_RELOAD_INTERVAL_MS, true);
	}

	void InitAccessListWatcher()
	{
		AdminLog(ACCESS_LOG_PREFIX + "Reloading access lists via HTTP every " + ACCESSLIST_RELOAD_INTERVAL_MS + "ms");
		GetGame().GetCallQueue(CALL_CATEGORY_SYSTEM).CallLater(this.ReloadAccessLists, ACCESSLIST_RELOAD_INTERVAL_MS, true);
	}


	void CheckCommands()
	{
		if (m_CommandsRequestInFlight)
			return;
		CleanupHandledCommands();
		FetchCommandsHttp();
	}

	void FetchCommandsHttp()
	{
		RestContext ctx = GetRestApi().GetRestContext(COMMANDS_HTTP_BASE_URL);
		if (!ctx)
		{
			AdminLog(COMMANDS_LOG_PREFIX + "HTTP context creation failed.");
			return;
		}
		m_CommandsRequestInFlight = true;
		ctx.GET(new CommandsHttpCallback(this), BuildServerPath(COMMANDS_HTTP_PATH));
	}

	void OnCommandsHttpSuccess(string data)
	{
		m_CommandsRequestInFlight = false;
		HandleCommandsText(data);
	}

	void OnCommandsHttpError(int errorCode)
	{
		m_CommandsRequestInFlight = false;
		AdminLog(COMMANDS_LOG_PREFIX + "HTTP error: " + errorCode);
	}

	void OnCommandsHttpTimeout()
	{
		m_CommandsRequestInFlight = false;
		AdminLog(COMMANDS_LOG_PREFIX + "HTTP timeout.");
	}

	void OnPushCodesSuccess(string data)
	{
    	AdminLog("[PushCodes] Success: " + data);
	}

	void OnPushCodesError(int errorCode)
	{
    	AdminLog("[PushCodes] HTTP error: " + errorCode);
	}

	void OnPushCodesTimeout()
	{
    	AdminLog("[PushCodes] HTTP timeout.");
	}


	void HandleCommandsText(string data)
	{
		if (data == "")
			return;
		data.Replace("\r", "");
		TStringArray rawLines = new TStringArray;
		data.Split("\n", rawLines);
		array<string> lines = new array<string>();
		for (int i = 0; i < rawLines.Count(); i++)
		{
			string line = rawLines.Get(i);
			line.Trim();
			if (line == "")
				continue;
			if (line.Substring(0, 1) == "#")
				continue;
			lines.Insert(line);
		}
		if (lines.Count() == 0)
			return;
		ProcessCommands(lines);
	}

	void ProcessCommands(array<string> lines)
	{
		for (int i = 0; i < lines.Count(); i++)
		{
			string line = lines[i];
			TStringArray parts = new TStringArray;
			line.Split(":", parts);
			if (parts.Count() < 3)
				continue;

			string cmdId = parts.Get(0);
			string userId = parts.Get(1);
			string cmd = parts.Get(2);
			if (cmdId == "" || userId == "" || cmd == "")
				continue;
			if (WasCommandHandled(cmdId))
				continue;

			PlayerBase user = FindPlayerById(userId);
			if (!user)
				continue;

			cmd.ToLower();
			if (cmd == "notify")
			{
				string message = JoinParts(parts, 3);
				if (message == "")
					continue;
				SendPlayerMessage(user, message);
				AdminLog(COMMANDS_LOG_PREFIX + "Notify " + userId + " -> " + message);
				MarkCommandHandled(cmdId);
				continue;
			}
			if (cmd == "give")
			{
    			string itemClass = JoinParts(parts, 3);
    			itemClass.Trim();

    			if (itemClass == "")
        			continue;

    			EntityAI created;

    			// Try to create in inventory first
    			created = user.GetInventory().CreateInInventory(itemClass);

    			// If inventory is full or item can't go there, spawn at feet
    			if (!created)
    			{
        			vector pos = user.GetPosition();
        			created = EntityAI.Cast(GetGame().CreateObject(itemClass, pos));
    			}

    			if (created)
    			{
        			AdminLog(COMMANDS_LOG_PREFIX + "Give " + userId + " -> " + itemClass);
        			SendPlayerMessage(user, "You received: " + itemClass);
    			}
    			else
    			{
        			AdminLog(COMMANDS_LOG_PREFIX + "Give FAILED for " + userId + " item=" + itemClass);
        			SendPlayerMessage(user, "Failed to spawn item: " + itemClass);
    			}

    			MarkCommandHandled(cmdId);
    			continue;
			}


			if (!user)
			{
				AdminLog("[Commands] Invalid or offline target: " + userId);
				continue;
			}

			if (cmd == "kick")
			{
				KickPlayerClean(user, "Kicked");
				AdminLog(COMMANDS_LOG_PREFIX + "Kick " + userId);
				MarkCommandHandled(cmdId);
				continue;
			}

			if (cmd == "kill")
			{
				user.SetHealth(0.0);
				AdminLog(COMMANDS_LOG_PREFIX + "Kill " + userId);
				MarkCommandHandled(cmdId);
				continue;
			}

			if (cmd == "godmode")
			{
				string mode = JoinParts(parts, 3);
				mode.ToLower();
				bool enabled = true;
				if (mode == "off" || mode == "0" || mode == "false")
					enabled = false;
				string modeLabel = "ON";
				if (!enabled)
					modeLabel = "OFF";
				ApplyGodMode(user, enabled);
				SendPlayerMessage(user, "Godmode: " + modeLabel);
				AdminLog(COMMANDS_LOG_PREFIX + "Godmode " + modeLabel + " -> " + userId);
				MarkCommandHandled(cmdId);
				continue;
			}

			if (cmd == "global")
			{
				string globalMessage = JoinParts(parts, 3);
				if (globalMessage == "")
					continue;
				NotificationSystem.SendNotificationToPlayerIdentityExtended( null, 10.0, "Admin Announcement:", globalMessage, "set:dayz_gui image:icon_twitter");
				AdminLog(COMMANDS_LOG_PREFIX + "Global -> " + globalMessage);
				MarkCommandHandled(cmdId);
				continue;
			}

			if (cmd == "teleport")
			{
				string target = JoinParts(parts, 3);
				vector destPos;
				bool hasDestPos = false;
				if (target.IndexOf(" ") > -1)
				{
					hasDestPos = ParseVectorFromString(target, destPos);
				}
				else
				{
					PlayerBase targetPlayer = FindPlayerById(target);
					if (targetPlayer)
					{
						destPos = targetPlayer.GetPosition();
						hasDestPos = true;
					}
				}
				if (!hasDestPos)
					continue;
				user.SetPosition(destPos);
				AdminLog(COMMANDS_LOG_PREFIX + "Teleport " + userId + " -> " + destPos);
				MarkCommandHandled(cmdId);
				continue;
			}
		}
	}

	bool WasCommandHandled(string cmdId)
	{
		if (cmdId == "")
			return true;
		return m_HandledCommandIds.Contains(cmdId);
	}

	void MarkCommandHandled(string cmdId)
	{
		if (cmdId == "")
			return;
		m_HandledCommandIds.Set(cmdId, GetGame().GetTime());
	}

	void CleanupHandledCommands()
	{
		int nowMs = GetGame().GetTime();
		TStringArray keys = m_HandledCommandIds.GetKeyArray();
		for (int i = keys.Count() - 1; i >= 0; i--)
		{
			string key = keys.Get(i);
			int lastMs = m_HandledCommandIds.Get(key);
			if (nowMs - lastMs > COMMANDS_DEDUP_MS)
				m_HandledCommandIds.Remove(key);
		}
	}

	void ReloadSafeZones()
	{
		AdminLog(ZONES_LOG_PREFIX + "Hot reload triggered.");
		LoadSafeZones();
		CheckSafeZones();
	}

	void LoadSafeZones()
	{
		int oldCount = m_SafeZones.Count();
		m_SafeZones.Clear();
		array<string> lines = new array<string>();
		if (!LoadIdListFromHttp(BuildServerPath(ZONES_HTTP_PATH), lines))
		{
			AdminLog(ZONES_LOG_PREFIX + "Failed to read zones via HTTP.");
			if (oldCount > 0)
				AdminLog(ZONES_LOG_PREFIX + "Removed all zones (read failed).");
			return;
		}
		for (int i = 0; i < lines.Count(); i++)
		{
			string line = lines[i];
			TStringArray parts = new TStringArray;
			line.Split(":", parts);
			if (parts.Count() < 3)
				continue;
			string name = parts.Get(0);
			string kind = parts.Get(1);
			if (kind == "poly")
			{
				array<vector> points = new array<vector>();
				TStringArray rawPoints = new TStringArray;
				parts.Get(2).Split("|", rawPoints);
				for (int p = 0; p < rawPoints.Count(); p++)
				{
					vector pt;
					if (ParsePoint2D(rawPoints.Get(p), pt))
						points.Insert(pt);
				}
				if (points.Count() < 3)
					continue;
				SafeZoneTrigger zonePoly = new SafeZoneTrigger(name, "0 0 0", 0);
				zonePoly.SetPolygon(points);
				m_SafeZones.Insert(zonePoly);
				AdminLog(ZONES_LOG_PREFIX + "Loaded polygon zone: " + name + " points=" + points.Count());
			}
			else if (kind == "admin")
			{
				if (parts.Count() < 9)
					continue;
				float ax = parts.Get(2).ToFloat();
				float ay = parts.Get(3).ToFloat();
				float az = parts.Get(4).ToFloat();
				float aradius = parts.Get(5).ToFloat();
				float tx = parts.Get(6).ToFloat();
				float ty = parts.Get(7).ToFloat();
				float tz = parts.Get(8).ToFloat();
				if (aradius <= 0)
					continue;
				vector pos = Vector(ax, ay, az);
				vector outPos = Vector(tx, ty, tz);
				SafeZoneTrigger zoneAdmin = new SafeZoneTrigger(name, pos, aradius);
				zoneAdmin.SetAdminZone(outPos);
				m_SafeZones.Insert(zoneAdmin);
				AdminLog(ZONES_LOG_PREFIX + "Loaded admin zone: " + name + " @ " + pos + " r=" + aradius + " out=" + outPos);
			}
			else
			{
				if (parts.Count() < 5)
					continue;
				float cx = parts.Get(1).ToFloat();
				float cy = parts.Get(2).ToFloat();
				float cz = parts.Get(3).ToFloat();
				float cradius = parts.Get(4).ToFloat();
				if (cradius <= 0)
					continue;
				vector cpos = Vector(cx, cy, cz);
				m_SafeZones.Insert(new SafeZoneTrigger(name, cpos, cradius));
				AdminLog(ZONES_LOG_PREFIX + "Loaded zone: " + name + " @ " + cpos + " r=" + cradius);
			}
		}
		int newCount = m_SafeZones.Count();
		if (oldCount > newCount)
			AdminLog(ZONES_LOG_PREFIX + "Removed zones: " + (oldCount - newCount));
		AdminLog(ZONES_LOG_PREFIX + "Loaded zones: " + newCount);
	}

	void CheckSafeZones()
	{
		array<Man> players = new array<Man>();
		GetGame().GetPlayers(players);

		if (m_SafeZones.Count() == 0)
		{
			if (m_PlayerSafeCounts.Count() > 0)
			{
				for (int p = 0; p < players.Count(); p++)
				{
					PlayerBase playerLocal = PlayerBase.Cast(players[p]);
					if (playerLocal)
						playerLocal.SetAllowDamage(true);
				}
				m_PlayerSafeCounts.Clear();
			}
			return;
		}

		ref map<string, int> seen = new map<string, int>();
		for (int i = 0; i < players.Count(); i++)
		{
			PlayerBase player = PlayerBase.Cast(players[i]);
			if (!player)
				continue;
			PlayerIdentity identity = player.GetIdentity();
			if (!identity)
				continue;
			string id = identity.GetId();
			if (id == "")
				continue;

			seen.Set(id, 1);
			vector pos = player.GetPosition();
			if (m_PlayerLastPos.Contains(id))
			{
				vector lastPos = m_PlayerLastPos.Get(id);
				float dx = pos[0] - lastPos[0];
				float dz = pos[2] - lastPos[2];
				float distSq = (dx * dx) + (dz * dz);
				if (distSq < ZONES_MIN_MOVE_SQ)
					continue;
			}
			m_PlayerLastPos.Set(id, pos);
			int newCount = 0;
			string zoneName = "";
			SafeZoneTrigger adminZone = null;
			for (int z = 0; z < m_SafeZones.Count(); z++)
			{
				SafeZoneTrigger zone = m_SafeZones[z];
				if (zone && zone.Contains(pos))
				{
					newCount++;
					if (zoneName == "")
						zoneName = zone.GetZoneName();
					if (zone.IsAdminZone())
						adminZone = zone;
				}
			}

			if (adminZone && !IsAdmin(identity))
			{
				vector outPos = adminZone.GetAdminTeleportOut();
				player.SetPosition(outPos);
				SendPlayerMessage(player, "Admin-only zone. You have been moved.");
				AdminLog(ZONES_LOG_PREFIX + "Non-admin redirected from admin zone: " + identity.GetName() + " -> " + outPos);
				continue;
			}

			int oldCount = 0;
			if (m_PlayerSafeCounts.Contains(id))
				oldCount = m_PlayerSafeCounts.Get(id);

			if (newCount > 0)
			{
				if (oldCount == 0)
					OnSafeZoneEnter(player, zoneName);
				else
					m_PlayerSafeCounts.Set(id, newCount);
			}
			else
			{
				if (oldCount > 0)
				{
					string lastZone = "";
					if (m_PlayerZoneName.Contains(id))
						lastZone = m_PlayerZoneName.Get(id);
					OnSafeZoneLeave(player, lastZone);
				}
			}
		}

		TStringArray keys = m_PlayerSafeCounts.GetKeyArray();
		for (int k = 0; k < keys.Count(); k++)
		{
			string key = keys.Get(k);
			if (!seen.Contains(key))
			{
				m_PlayerSafeCounts.Remove(key);
				m_PlayerZoneName.Remove(key);
				m_PlayerLastPos.Remove(key);
			}
		}
	}

	void OnSafeZoneEnter(PlayerBase player, string zoneName)
	{
		if (!player)
			return;
		PlayerIdentity identity = player.GetIdentity();
		if (!identity)
			return;
		string id = identity.GetId();
		if (id == "")
			return;

		int count = 0;
		if (m_PlayerSafeCounts.Contains(id))
			count = m_PlayerSafeCounts.Get(id);
		count++;
		m_PlayerSafeCounts.Set(id, count);
		if (zoneName != "")
			m_PlayerZoneName.Set(id, zoneName);

		player.SetAllowDamage(false);
		MaximizePlayerStats(player);
		if (zoneName != "")
		{
			AdminLog(ZONES_LOG_PREFIX + "Notify enter: " + identity.GetName() + " -> " + zoneName);
			SendPlayerMessage(player, "Entered Safe Zone: " + zoneName);
		}
		AdminLog(ZONES_LOG_PREFIX + "Enter: " + identity.GetName() + " (" + id + ")");
	}

	void OnSafeZoneLeave(PlayerBase player, string zoneName)
	{
		if (!player)
			return;
		PlayerIdentity identity = player.GetIdentity();
		if (!identity)
			return;
		string id = identity.GetId();
		if (id == "")
			return;

		int count = 0;
		if (m_PlayerSafeCounts.Contains(id))
			count = m_PlayerSafeCounts.Get(id);
		count = count - 1;
		if (count <= 0)
		{
			m_PlayerSafeCounts.Remove(id);
			m_PlayerZoneName.Remove(id);
			player.SetAllowDamage(true);
		}
		else
		{
			m_PlayerSafeCounts.Set(id, count);
		}
		if (zoneName != "")
		{
			AdminLog(ZONES_LOG_PREFIX + "Notify exit: " + identity.GetName() + " -> " + zoneName);
			SendPlayerMessage(player, "Left Safe Zone: " + zoneName);
		}
		AdminLog(ZONES_LOG_PREFIX + "Exit: " + identity.GetName() + " (" + id + ")");
	}

	void MaximizePlayerStats(PlayerBase player)
	{
		if (!player)
			return;
		player.SetFullHealth();
		player.SetHealthMax("GlobalHealth", "Blood");
		player.SetHealthMax("GlobalHealth", "Health");
		player.SetHealthMax("GlobalHealth", "Shock");
		if (player.GetStatEnergy())
			player.GetStatEnergy().Set(player.GetStatEnergy().GetMax());
		if (player.GetStatWater())
			player.GetStatWater().Set(player.GetStatWater().GetMax());
		AdminLog(ZONES_LOG_PREFIX + "Max stats applied to " + player.GetIdentity().GetName());
	}

	void ApplyGodMode(PlayerBase player, bool enabled)
	{
		/*if (!player)
			return;
		player.SetAllowDamage(!enabled);
		if (enabled)
			MaximizePlayerStats(player);*/
		InvokeOnDisconnect(player);
	}

	PlayerBase FindPlayerById(string id)
	{
		array<Man> players = new array<Man>();
		GetGame().GetPlayers(players);
		for (int i = 0; i < players.Count(); i++)
		{
			PlayerBase player = PlayerBase.Cast(players[i]);
			if (!player)
				continue;
			PlayerIdentity identity = player.GetIdentity();
			if (!identity)
				continue;
			if (identity.GetId() == id)
				return player;
		}
		return null;
	}

	void SendPlayerMessage(PlayerBase player, string message)
	{
		if (!player)
			return;
		PlayerIdentity identity = player.GetIdentity();
		if (!identity)
			return;
		Param1<string> msgParam = new Param1<string>(message);
		GetGame().RPCSingleParam(player, ERPCs.RPC_USER_ACTION_MESSAGE, msgParam, true, identity);
	}

	void SendGlobalMessage(string message)
	{
		array<Man> players = new array<Man>();
		GetGame().GetPlayers(players);
		for (int i = 0; i < players.Count(); i++)
		{
			PlayerBase player = PlayerBase.Cast(players[i]);
			if (!player)
				continue;
			SendPlayerMessage(player, message);
		}
	}


	bool ParseVectorFromString(string value, out vector outPos)
	{
		TStringArray parts = new TStringArray;
		value.Split(" ", parts);
		if (parts.Count() < 3)
			return false;
		float x = parts.Get(0).ToFloat();
		float y = parts.Get(1).ToFloat();
		float z = parts.Get(2).ToFloat();
		outPos = Vector(x, y, z);
		return true;
	}

	bool ParsePoint2D(string value, out vector outPos)
	{
		TStringArray parts = new TStringArray;
		value.Trim();
		value.Split(" ", parts);
		if (parts.Count() < 2)
			return false;
		float x = parts.Get(0).ToFloat();
		float z = parts.Get(1).ToFloat();
		outPos = Vector(x, 0, z);
		return true;
	}

	string JoinParts(TStringArray parts, int startIndex)
	{
		string result = "";
		for (int i = startIndex; i < parts.Count(); i++)
		{
			if (i > startIndex)
				result += ":";
			result += parts.Get(i);
		}
		return result;
	}

	void KickPlayerClean(PlayerBase player, string reason)
	{
		OnClientDisconnectedEvent(player.GetIdentity(), player, 0, true);
	}

	void InitAccessLists()
	{
		EnsureConfigDir();
		LoadIdListToMapHttp(ADMINLIST_HTTP_PATH, m_AdminIds, "admins");
		LoadIdListToMapHttp(MODLIST_HTTP_PATH, m_ModIds, "mods");
		InitAccessListWatcher();
	}

	void ReloadAccessLists()
	{
		LoadIdListToMapHttp(ADMINLIST_HTTP_PATH, m_AdminIds, "admins");
		LoadIdListToMapHttp(MODLIST_HTTP_PATH, m_ModIds, "mods");
	}

	void EnsureConfigDir()
	{
		// HTTP mode: no local config directory required.
	}

	void EnsureConfigFiles()
	{
		// HTTP mode: files served by remote server.
	}

	void LogServerDzFileAsync()
	{
		if (m_ServerDzLogged)
			return;
		GetGame().GetCallQueue(CALL_CATEGORY_SYSTEM).CallLater(this.LogServerDzFile, 1000, false);
	}

	void LogServerDzFile()
	{
		if (m_ServerDzLogged)
			return;
		m_ServerDzLogged = true;
		FileHandle fh = OpenFile(RCON_CFG_PATH, FileMode.READ);
		if (fh == 0)
			return;
		Print("[serverDZ.cfg] BEGIN " + RCON_CFG_PATH);
		string line;
		while (FGets(fh, line) >= 0)
		{
			line.Replace("\r", "");
			line.Replace("\n", "");
			Print("[serverDZ.cfg] " + line);
		}
		CloseFile(fh);
		Print("[serverDZ.cfg] END");
	}

	void LoadZombieSpawnZones()
	{
		array<ref ZombieSpawnZone> oldZones = new array<ref ZombieSpawnZone>();
		for (int oz = 0; oz < m_ZombieSpawnZones.Count(); oz++)
			oldZones.Insert(m_ZombieSpawnZones[oz]);
		m_ZombieSpawnZones.Clear();
		m_ZombieRefillByZone.Clear();
		array<string> spawnFiles = new array<string>();
		if (!LoadSpawnFileList(spawnFiles))
		{
			AdminLog(ZED_LOG_PREFIX + "No spawn_*.txt files found (HTTP list)." );
			CleanupRemovedZombieZones(oldZones, new array<ref ZombieSpawnZone>());
			return;
		}
		for (int f = 0; f < spawnFiles.Count(); f++)
		{
			string fileName = spawnFiles[f];
			string zoneBaseName = GetZoneNameFromFileName(fileName);
			string data;
			if (!HttpGetText(BuildServerPath(fileName), data))
				continue;
			array<string> lines = new array<string>();
			ExtractNonEmptyLines(data, lines);
			int lineIndex = 0;
			for (int l = 0; l < lines.Count(); l++)
			{
				string line = lines[l];
				TStringArray parts = new TStringArray;
				line.Split(":", parts);
				if (parts.Count() < 6)
					continue;
				float x = parts.Get(0).ToFloat();
				float y = parts.Get(1).ToFloat();
				float z = parts.Get(2).ToFloat();
				float radius = parts.Get(3).ToFloat();
				int minCount = parts.Get(4).ToInt();
				int maxCount = parts.Get(5).ToInt();
				if (radius <= 0 || maxCount <= 0)
					continue;
				if (minCount < 0)
					minCount = 0;
				if (maxCount < minCount)
					maxCount = minCount;
				string zoneName = zoneBaseName;
				if (lineIndex > 0)
					zoneName = zoneBaseName + "#" + (lineIndex + 1);
				ZombieSpawnZone zone = new ZombieSpawnZone(zoneName, Vector(x, y, z), radius, minCount, maxCount);
				if (parts.Count() >= 7)
				{
					array<vector> points = new array<vector>();
					TStringArray rawPoints = new TStringArray;
					parts.Get(6).Split("|", rawPoints);
					for (int p = 0; p < rawPoints.Count(); p++)
					{
						vector pt;
						if (ParseVectorFromString(rawPoints.Get(p), pt))
							points.Insert(pt);
					}
					if (points.Count() > 0)
						zone.SetPoints(points);
				}
				m_ZombieSpawnZones.Insert(zone);
				lineIndex++;
			}
		}
		AdminLog(ZED_LOG_PREFIX + "Loaded spawn zones: " + m_ZombieSpawnZones.Count());
		CleanupRemovedZombieZones(oldZones, m_ZombieSpawnZones);
	}

	void CleanupRemovedZombieZones(array<ref ZombieSpawnZone> oldZones, array<ref ZombieSpawnZone> newZones)
	{
		for (int i = 0; i < oldZones.Count(); i++)
		{
			ZombieSpawnZone oldZone = oldZones[i];
			if (!oldZone)
				continue;
			if (!ZombieZoneExists(oldZone.m_Name, newZones))
			{
				DeleteZombiesInZone(oldZone);
				m_ZombieRefillByZone.Remove(oldZone.m_Name);
				AdminLog(ZED_LOG_PREFIX + "Removed zone: " + oldZone.m_Name);
			}
		}
	}

	bool ZombieZoneExists(string name, array<ref ZombieSpawnZone> zones)
	{
		for (int i = 0; i < zones.Count(); i++)
		{
			ZombieSpawnZone zone = zones[i];
			if (zone && zone.m_Name == name)
				return true;
		}
		return false;
	}

	void DeleteZombiesInZone(ZombieSpawnZone zone)
	{
		if (!zone)
			return;
		ref array<ZombieBase> tracked;
		if (m_ZombieTrackedByZone.Find(zone.m_Name, tracked))
		{
			for (int t = tracked.Count() - 1; t >= 0; t--)
			{
				ZombieBase zbTracked = tracked[t];
				if (zbTracked)
					GetGame().ObjectDelete(zbTracked);
				tracked.Remove(t);
			}
			m_ZombieTrackedByZone.Remove(zone.m_Name);
		}
		array<Object> objects = new array<Object>();
		GetGame().GetObjectsAtPosition(zone.m_Center, zone.m_Radius, objects, null);
		for (int i = 0; i < objects.Count(); i++)
		{
			Object obj = objects[i];
			if (!obj)
				continue;
			if (obj.IsInherited(ZombieBase))
				GetGame().ObjectDelete(obj);
		}
	}

	void ProcessZombieSpawnZones()
	{
		if (!m_ZombieSpawnZones || m_ZombieSpawnZones.Count() == 0)
			return;
		for (int i = 0; i < m_ZombieSpawnZones.Count(); i++)
		{
			ZombieSpawnZone zone = m_ZombieSpawnZones[i];
			if (!zone)
				continue;

			if (!IsPlayerNearZone(zone))
				continue;

			ref array<ZombieBase> tracked;
			if (!m_ZombieTrackedByZone.Find(zone.m_Name, tracked))
			{
				tracked = new array<ZombieBase>();
				m_ZombieTrackedByZone.Set(zone.m_Name, tracked);
			}

			for (int k = tracked.Count() - 1; k >= 0; k--)
			{
				ZombieBase zbTracked = tracked[k];
				if (!zbTracked || !zbTracked.IsAlive())
					tracked.Remove(k);
			}

			int count = tracked.Count();

			bool refill = false;
			if (m_ZombieRefillByZone.Contains(zone.m_Name))
				refill = m_ZombieRefillByZone.Get(zone.m_Name);

			if (count < zone.m_MinCount)
				refill = true;
			if (count >= zone.m_MaxCount)
				refill = false;
			m_ZombieRefillByZone.Set(zone.m_Name, refill);

			if (refill && count < zone.m_MaxCount)
			{
				if (m_ZombieSpawnClasses && m_ZombieSpawnClasses.Count() > 0)
				{
					vector spawnPos = zone.GetSpawnPoint();
					string zClass = m_ZombieSpawnClasses.GetRandomElement();
					Object obj = GetGame().CreateObject(zClass, spawnPos, false, true);
					ZombieBase zbNew = ZombieBase.Cast(obj);
					if (zbNew)
					{
						tracked.Insert(zbNew);
						AdminLog(ZED_LOG_PREFIX + "Spawned " + zClass + " in zone " + zone.m_Name + " @ " + spawnPos);
					}
					else
					{
						AdminLog(ZED_LOG_PREFIX + "Spawn failed (cast) for " + zClass + " in zone " + zone.m_Name + " @ " + spawnPos);
					}
				}
			}
		}
	}

	string GetZoneNameFromFileName(string fileName)
	{
		string name = fileName;
		name.Replace("spawn_", "");
		int dot = name.IndexOf(".txt");
		if (dot > 0)
			name = name.Substring(0, dot);
		return name;
	}

	string GeneratePlayerCode()
	{
		string code;

		code = "" + Math.RandomInt(1000, 9999);

		while (m_CodeToUID.Contains(code))
		{
			code = "" + Math.RandomInt(1000, 9999);
		}

		return code;
	}




	bool IsPlayerNearZone(ZombieSpawnZone zone)
	{
		if (!zone)
			return false;
		array<Man> players = new array<Man>();
		GetGame().GetPlayers(players);
		float radiusSq = ZED_SPAWN_PLAYER_RADIUS * ZED_SPAWN_PLAYER_RADIUS;
		for (int i = 0; i < players.Count(); i++)
		{
			PlayerBase player = PlayerBase.Cast(players[i]);
			if (!player)
				continue;
			vector pos = player.GetPosition();
			float dx = pos[0] - zone.m_Center[0];
			float dz = pos[2] - zone.m_Center[2];
			if ((dx * dx) + (dz * dz) <= radiusSq)
				return true;

			if (zone.m_Points && zone.m_Points.Count() > 0)
			{
				for (int p = 0; p < zone.m_Points.Count(); p++)
				{
					vector pt = zone.m_Points[p];
					float px = pos[0] - pt[0];
					float pz = pos[2] - pt[2];
					if ((px * px) + (pz * pz) <= radiusSq)
						return true;
				}
			}
		}
		return false;
	}

	void ApplySpawnOverride(string beId, inout vector spawnPos)
	{
		if (beId == "")
			return;
		array<string> lines = new array<string>();
		if (!LoadIdListFromHttp(BuildServerPath(SPAWN_HTTP_PATH), lines))
			return;
		for (int i = 0; i < lines.Count(); i++)
		{
			string line = lines[i];
			int sep = line.IndexOf(":");
			if (sep <= 0)
				continue;
			string id = line.Substring(0, sep);
			if (id != beId)
				continue;
			string coords = line.Substring(sep + 1, line.Length() - (sep + 1));
			vector parsed;
			if (ParseVectorFromString(coords, parsed))
			{
				spawnPos = parsed;
				AdminLog("[SpawnOverride] " + beId + " -> " + spawnPos);
			}
			return;
		}
	}


	void LoadIdListToMap(string path, TStringMap target, string label)
	{
		target.Clear();
		array<string> list = new array<string>();
		if (!LoadIdListFromHttp(path, list))
		{
			AdminLog(ACCESS_LOG_PREFIX + "Failed to read " + label + " list via HTTP: " + path);
			return;
		}
		for (int i = 0; i < list.Count(); i++)
		{
			string id = list[i];
			if (id != "")
				target.Insert(id, "");
		}
		AdminLog(ACCESS_LOG_PREFIX + "Loaded " + label + ": " + target.Count());
	}

	void LoadIdListToMapHttp(string path, TStringMap target, string label)
	{
		LoadIdListToMap(BuildServerPath(path), target, label);
	}

	bool LoadIdListFromHttp(string path, out array<string> list)
	{
		list = new array<string>();
		string data;
		if (!HttpGetText(path, data))
			return false;
		ExtractNonEmptyLines(data, list);
		return true;
	}


	bool HttpGetText(string path, out string data)
	{
		data = "";
		if (!IsServerIdConfigured())
		{
			AdminLog(COMMANDS_LOG_PREFIX + "Server ID not configured. Set SERVER_ID.");
			return false;
		}
		RestContext ctx = GetRestApi().GetRestContext(COMMANDS_HTTP_BASE_URL);
		if (!ctx)
			return false;
		data = ctx.GET_now(path);
		if (data == "")
			return false;
		return true;
	}

	void ExtractNonEmptyLines(string data, out array<string> lines)
	{
		lines = new array<string>();
		data.Replace("\r", "");
		TStringArray rawLines = new TStringArray;
		data.Split("\n", rawLines);
		for (int i = 0; i < rawLines.Count(); i++)
		{
			string line = rawLines.Get(i);
			line.Trim();
			if (line == "")
				continue;
			if (line.Substring(0, 1) == "#")
				continue;
			lines.Insert(line);
		}
	}

	bool LoadSpawnFileList(out array<string> files)
	{
		files = new array<string>();
		string data;
		if (!HttpGetText(BuildServerPath(ZED_SPAWN_LIST_HTTP_PATH), data))
			return false;
		array<string> lines = new array<string>();
		ExtractNonEmptyLines(data, lines);
		for (int i = 0; i < lines.Count(); i++)
		{
			string name = lines[i];
			if (name == "")
				continue;
			if (name.IndexOf("spawn_") != 0)
				continue;
			int dot = name.IndexOf(".txt");
			if (dot < 0)
				continue;
			files.Insert(name);
		}
		return files.Count() > 0;
	}

	void PushPlayerCodes()
	{
    	if (!m_CodeToUID || m_CodeToUID.Count() == 0)
        	return;

    	string payload = "";
    	foreach (string code, string uid : m_CodeToUID)
    	{
        	string name = "";
        	if (m_UIDToName.Contains(uid))
            	name = m_UIDToName.Get(uid);

        	payload += code + ":" + uid + ":" + name + "\n";
    	}

    	string url = BuildServerPath("updateCodes.php");

    	RestContext ctx = GetRestApi().GetRestContext(COMMANDS_HTTP_BASE_URL);
    	if (!ctx)
    	{
        	AdminLog("[PushCodes] HTTP context creation failed.");
        	return;
    	}

    	string postData = "serverID=" + SERVER_ID + "&data=" + payload;

    	ctx.POST(new PushCodesHttpCallback(this), url, postData);

    	AdminLog("[PushCodes] Sent " + m_CodeToUID.Count() + " codes.");
	}

	void DeletePlayerCodeFromServer(string uid)
	{
    	RestContext ctx = GetRestApi().GetRestContext(COMMANDS_HTTP_BASE_URL);
    	if (!ctx)
    	{
        	AdminLog("[DeleteCode] HTTP context creation failed.");
        	return;
    	}

    	string postData = "serverID=" + SERVER_ID + "&uid=" + uid;

    	ctx.POST(new PushCodesHttpCallback(this), BuildServerPath("deleteCode.php"), postData);

    	AdminLog("[DeleteCode] Requested delete for UID: " + uid);
	}





	override void OnMissionFinish()
	{
		super.OnMissionFinish();

		GetGame().GetCallQueue(CALL_CATEGORY_SYSTEM).Remove(PushPlayerCodes);
	}



	bool IsServerIdConfigured()
	{
		if (SERVER_ID == "" || SERVER_ID == "CHANGE_ME")
			return false;
		return true;
	}

	string BuildServerPath(string endpoint)
	{
		return "/dayz/" + endpoint + "?serverID=" + SERVER_ID;
	}

	bool IsAdmin(PlayerIdentity identity)
	{
		if (!identity)
			return false;
		return m_AdminIds.Contains(identity.GetId());
	}

	bool IsMod(PlayerIdentity identity)
	{
		if (!identity)
			return false;
		return m_ModIds.Contains(identity.GetId());
	}

};
