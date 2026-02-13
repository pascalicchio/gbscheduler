# Building GB Scheduler API for Agent Access

## 🎯 Goal

Make your academy data from gbscheduler.com accessible to your OpenClaw agent so it can:
- Pull real enrollment numbers
- Analyze class capacity trends
- Track member retention
- Give you actual business insights (not generic advice)

---

## 📊 Current State

**What you have:**
- gbscheduler.com (your custom system for managing academy data)
- CSV exports (manual process)
- ZenPlanner data → exported to gbscheduler.com

**What you need:**
- Simple API endpoint the agent can query
- Real-time access to academy metrics
- No manual CSV downloads

---

## ✅ Option 1: Simple Read-Only API (Recommended)

**Build a lightweight REST API on gbscheduler.com**

### Architecture:
```
OpenClaw Agent
    ↓ (HTTP GET request)
gbscheduler.com/api/metrics
    ↓ (query database)
MySQL/PostgreSQL
    ↓ (return JSON)
Agent gets data
```

### Endpoints to Create:

**1. Academy Overview**
```
GET /api/v1/academies

Response:
{
  "davenport": {
    "total_members": 360,
    "active_members": 342,
    "new_this_month": 8,
    "churned_this_month": 2,
    "mrr": 32400,
    "last_updated": "2026-02-13T20:00:00Z"
  },
  "celebration": {
    "total_members": 170,
    "active_members": 165,
    "new_this_month": 3,
    "churned_this_month": 1,
    "mrr": 15300,
    "last_updated": "2026-02-13T20:00:00Z"
  }
}
```

**2. Class Capacity**
```
GET /api/v1/classes/capacity?location=davenport

Response:
{
  "classes": [
    {
      "name": "Tuesday 6pm All Levels",
      "capacity": 30,
      "avg_attendance": 28,
      "utilization": 0.93,
      "trend": "increasing"
    },
    {
      "name": "Thursday 6pm All Levels",
      "capacity": 30,
      "avg_attendance": 19,
      "utilization": 0.63,
      "trend": "stable"
    }
  ]
}
```

**3. Enrollment Trends**
```
GET /api/v1/trends?location=davenport&period=30days

Response:
{
  "enrollments": [
    {"date": "2026-02-01", "count": 2},
    {"date": "2026-02-08", "count": 3},
    {"date": "2026-02-15", "count": 3}
  ],
  "churn": [
    {"date": "2026-02-01", "count": 1},
    {"date": "2026-02-15", "count": 1}
  ],
  "net_growth": 6
}
```

**4. Revenue Snapshot**
```
GET /api/v1/revenue?location=davenport

Response:
{
  "mrr": 32400,
  "mrr_last_month": 30800,
  "growth_percent": 5.2,
  "projected_annual": 388800
}
```

### Quick Build Guide (PHP/Node.js):

**Using PHP (if gbscheduler.com is PHP):**
```php
<?php
// /api/v1/academies.php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Adjust for security

// Your API key validation
$api_key = $_GET['key'] ?? '';
if ($api_key !== 'your_secret_key_here') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Connect to your database
$db = new PDO('mysql:host=localhost;dbname=gbscheduler', 'user', 'pass');

// Query Davenport metrics
$davenport = $db->query("
    SELECT 
        COUNT(*) as total_members,
        SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) as active_members,
        SUM(monthly_fee) as mrr
    FROM members 
    WHERE location = 'davenport'
")->fetch(PDO::FETCH_ASSOC);

// Query Celebration metrics  
$celebration = $db->query("
    SELECT 
        COUNT(*) as total_members,
        SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) as active_members,
        SUM(monthly_fee) as mrr
    FROM members 
    WHERE location = 'celebration'
")->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    'davenport' => $davenport,
    'celebration' => $celebration,
    'last_updated' => date('c')
]);
```

**Using Node.js/Express:**
```javascript
// server.js
const express = require('express');
const mysql = require('mysql2/promise');

const app = express();
const API_KEY = 'your_secret_key_here';

// Middleware to check API key
app.use((req, res, next) => {
    if (req.query.key !== API_KEY) {
        return res.status(401).json({ error: 'Unauthorized' });
    }
    next();
});

// Database connection
const pool = mysql.createPool({
    host: 'localhost',
    user: 'root',
    password: 'your_password',
    database: 'gbscheduler'
});

// Academy overview endpoint
app.get('/api/v1/academies', async (req, res) => {
    try {
        const [davenport] = await pool.query(`
            SELECT 
                COUNT(*) as total_members,
                SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) as active_members,
                SUM(monthly_fee) as mrr
            FROM members 
            WHERE location = 'davenport'
        `);
        
        const [celebration] = await pool.query(`
            SELECT 
                COUNT(*) as total_members,
                SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) as active_members,
                SUM(monthly_fee) as mrr
            FROM members 
            WHERE location = 'celebration'
        `);
        
        res.json({
            davenport: davenport[0],
            celebration: celebration[0],
            last_updated: new Date().toISOString()
        });
    } catch (error) {
        res.status(500).json({ error: error.message });
    }
});

app.listen(3000, () => console.log('API running on port 3000'));
```

### Security:

**1. API Key Authentication:**
```bash
# Generate a secure key
openssl rand -hex 32

# Use it in requests
curl "https://gbscheduler.com/api/v1/academies?key=YOUR_KEY_HERE"
```

**2. Store key securely:**
```bash
# In OpenClaw
echo "GB_SCHEDULER_API_KEY=your_key_here" >> ~/.openclaw/.env

# Reference in agent scripts
export $(cat ~/.openclaw/.env | xargs)
```

**3. Rate limiting (optional):**
```php
// Simple rate limit: max 100 requests/hour per key
$cache_file = "/tmp/api_rate_limit_{$api_key}.txt";
$requests = file_exists($cache_file) ? (int)file_get_contents($cache_file) : 0;

if ($requests > 100) {
    http_response_code(429);
    echo json_encode(['error' => 'Rate limit exceeded']);
    exit;
}

file_put_contents($cache_file, $requests + 1);
```

---

## ✅ Option 2: Use Existing ZenPlanner API

**Wait - ZenPlanner DOES have an API!**

From your memory: "API pricing at $50/month per location"

**ZenPlanner API endpoints:**
- Members list
- Attendance records
- Billing information
- Class schedules

**You might not need GB Scheduler API if you use ZenPlanner directly.**

**Cost comparison:**
- ZenPlanner API: $100/month (both locations)
- Building GB Scheduler API: Free (just development time)
- Hybrid: Use ZenPlanner API + cache in GB Scheduler for free queries

**My recommendation:**
1. **For now:** Build simple GB Scheduler API (free, you control it)
2. **Later:** Evaluate ZenPlanner API if you need real-time data

---

## 🔌 How Agent Will Use It

**In your nightly surprise script:**
```bash
#!/bin/bash

# Fetch academy data
METRICS=$(curl -s "https://gbscheduler.com/api/v1/academies?key=$GB_SCHEDULER_API_KEY")

# Pass to OpenClaw agent
openclaw message --agent main << EOF
Execute nightly surprise from HEARTBEAT.md.

Here's today's academy data:
$METRICS

Use this REAL data to provide a specific insight.
Look for:
- Trends (enrollment up/down)
- Capacity issues (classes >90% full)
- Revenue changes
- Retention concerns

Be specific. Show the numbers.
EOF
```

**Agent response example:**
```
🎁 Nightly Surprise

Fillipe - enrollment spike alert!

Davenport added 8 new members this month (vs 4/month average).
Your MRR jumped from $30.8K to $32.4K (+5.2%).

But: Tuesday 6pm class is now at 93% capacity (28/30 avg attendance).

Action needed: Add waitlist or second session before you hit 100% and turn away interested students.

Want me to draft the scheduling options?
```

---

## 📋 Implementation Checklist

**Phase 1: Minimum Viable API (2 hours)**
- [ ] Create `/api/v1/academies` endpoint
- [ ] Return member counts and MRR
- [ ] Add API key authentication
- [ ] Test with curl

**Phase 2: Class Capacity (1 hour)**
- [ ] Create `/api/v1/classes/capacity` endpoint
- [ ] Calculate utilization percentages
- [ ] Add trend indicators

**Phase 3: Integration with Agent (30 min)**
- [ ] Store API key in `~/.openclaw/.env`
- [ ] Update nightly surprise script to fetch data
- [ ] Test with real data

**Phase 4: Additional Endpoints (optional)**
- [ ] Revenue trends
- [ ] Retention metrics
- [ ] Lead conversion (once GHL connected)

---

## 🧪 Testing Your API

**1. Test manually:**
```bash
# Basic endpoint
curl "https://gbscheduler.com/api/v1/academies?key=YOUR_KEY"

# Should return JSON with member data
```

**2. Test with OpenClaw:**
```bash
# Add to your test script
GB_API_KEY="your_key_here"
DATA=$(curl -s "https://gbscheduler.com/api/v1/academies?key=$GB_API_KEY")
echo "Academy data: $DATA"
```

**3. Test error handling:**
```bash
# Wrong key
curl "https://gbscheduler.com/api/v1/academies?key=wrong_key"
# Should return 401 Unauthorized

# Missing key
curl "https://gbscheduler.com/api/v1/academies"
# Should return 401 Unauthorized
```

---

## 💡 Pro Tips

**1. Cache expensive queries:**
```php
// Cache for 1 hour
$cache_file = "/tmp/api_cache_academies.json";
$cache_age = time() - filemtime($cache_file);

if (file_exists($cache_file) && $cache_age < 3600) {
    echo file_get_contents($cache_file);
    exit;
}

// Query database, save to cache
$data = json_encode($results);
file_put_contents($cache_file, $data);
echo $data;
```

**2. Add timestamps to track freshness:**
```json
{
  "davenport": {...},
  "celebration": {...},
  "generated_at": "2026-02-13T20:00:00Z",
  "data_as_of": "2026-02-13T19:45:00Z"
}
```

**3. Version your API:**
```
/api/v1/academies  ← Current
/api/v2/academies  ← Future improvements
```

**4. Log API usage:**
```php
// Simple logging
file_put_contents(
    '/var/log/gbscheduler-api.log',
    date('Y-m-d H:i:s') . " - {$_SERVER['REMOTE_ADDR']} - {$_SERVER['REQUEST_URI']}\n",
    FILE_APPEND
);
```

---

## 🎯 Bottom Line

**You have 2 options:**

**Option A: Build GB Scheduler API** (recommended for now)
- ✅ Free
- ✅ You control it
- ✅ Can combine ZenPlanner exports with custom data
- ✅ 2-3 hours to build
- ⏱️ Setup time: This weekend

**Option B: Use ZenPlanner API directly**
- ✅ Real-time data
- ✅ No development needed
- ❌ $100/month
- ❌ Less flexible
- ⏱️ Setup time: 30 minutes

**My recommendation:**
Build Option A first. It's free and gives you full control. You can always add ZenPlanner API later if you need real-time data.

Want me to help you build the actual API code? I can generate the complete PHP or Node.js files based on your GB Scheduler database schema.
