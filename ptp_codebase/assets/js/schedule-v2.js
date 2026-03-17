/**
 * PTP Schedule Calendar v3 — Acuity-style
 * Custom time-grid calendar (no FullCalendar dependency)
 */
(function(){
'use strict';

const DOW = ['SUN','MON','TUE','WED','THU','FRI','SAT'];
const MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];
const SLOT_H = 48; // px per 30min
const START_HOUR = 6;
const END_HOUR = 22;
const SLOTS = (END_HOUR - START_HOUR) * 2;

let currentDate = new Date();
let viewMode = 'week'; // week | day
let events = [];
let selectedTrainers = [];
let trainerColors = {};
let miniMonth, miniYear;
let statsCache = {};

document.addEventListener('DOMContentLoaded', init);

function init(){
  if(!document.querySelector('.sc')) return;
  
  // Build trainer data
  if(window.PTPSchedule && PTPSchedule.trainers){
    PTPSchedule.trainers.forEach(function(t){
      selectedTrainers.push(t.id);
      trainerColors[t.id] = t.color;
    });
  }
  
  // Set today
  currentDate = new Date();
  miniMonth = currentDate.getMonth();
  miniYear = currentDate.getFullYear();
  
  bindNav();
  bindTrainers();
  bindNewSession();
  renderMiniCal();
  renderCalendar();
  loadStats();
}

// ===== Navigation =====
function bindNav(){
  var prev = document.getElementById('scPrev');
  var next = document.getElementById('scNext');
  var today = document.getElementById('scToday');
  var vWeek = document.getElementById('scViewWeek');
  var vDay = document.getElementById('scViewDay');
  
  if(prev) prev.onclick = function(){ nav(-1); };
  if(next) next.onclick = function(){ nav(1); };
  if(today) today.onclick = function(){ currentDate = new Date(); renderCalendar(); renderMiniCal(); };
  if(vWeek) vWeek.onclick = function(){ viewMode='week'; renderCalendar(); setViewActive(); };
  if(vDay) vDay.onclick = function(){ viewMode='day'; renderCalendar(); setViewActive(); };
}

function nav(dir){
  var d = new Date(currentDate);
  if(viewMode === 'week') d.setDate(d.getDate() + dir * 7);
  else d.setDate(d.getDate() + dir);
  currentDate = d;
  renderCalendar();
  renderMiniCal();
}

function setViewActive(){
  document.querySelectorAll('.sc-view-btn').forEach(function(b){ b.classList.remove('active'); });
  var id = viewMode === 'week' ? 'scViewWeek' : 'scViewDay';
  var el = document.getElementById(id);
  if(el) el.classList.add('active');
}

// ===== Render Calendar =====
function renderCalendar(){
  var days = getDays();
  updateTitle(days);
  renderHeaders(days);
  renderGrid(days);
  loadEvents(days);
}

function getDays(){
  if(viewMode === 'day') return [new Date(currentDate)];
  // Week: Monday to Sunday
  var d = new Date(currentDate);
  var dow = d.getDay();
  var mon = new Date(d);
  mon.setDate(d.getDate() - ((dow + 6) % 7)); // Monday
  var days = [];
  for(var i=0;i<7;i++){
    var dd = new Date(mon);
    dd.setDate(mon.getDate()+i);
    days.push(dd);
  }
  return days;
}

function updateTitle(days){
  var el = document.getElementById('scTitle');
  if(!el) return;
  if(days.length === 1){
    el.textContent = MONTHS[days[0].getMonth()] + ' ' + days[0].getDate() + ', ' + days[0].getFullYear();
  } else {
    var s = days[0], e = days[6];
    if(s.getMonth() === e.getMonth()){
      el.textContent = MONTHS[s.getMonth()] + ' ' + s.getDate() + ' - ' + e.getDate() + ', ' + s.getFullYear();
    } else {
      el.textContent = MONTHS[s.getMonth()].substr(0,3) + ' ' + s.getDate() + ' - ' + MONTHS[e.getMonth()].substr(0,3) + ' ' + e.getDate() + ', ' + e.getFullYear();
    }
  }
}

function renderHeaders(days){
  var wrap = document.getElementById('scHdrCols');
  if(!wrap) return;
  wrap.style.gridTemplateColumns = 'repeat('+days.length+',1fr)';
  var today = fmtDate(new Date());
  var h = '';
  days.forEach(function(d){
    var isToday = fmtDate(d) === today;
    h += '<div class="sc-day-hdr'+(isToday?' today':'')+'">';
    h += '<div class="dow">'+DOW[d.getDay()]+'</div>';
    h += '<div class="dom">'+d.getDate()+'</div>';
    h += '</div>';
  });
  wrap.innerHTML = h;
}

function renderGrid(days){
  var colsEl = document.getElementById('scCols');
  var timesEl = document.getElementById('scTimes');
  if(!colsEl || !timesEl) return;
  
  colsEl.style.gridTemplateColumns = 'repeat('+days.length+',1fr)';
  
  // Time labels
  var th = '';
  for(var i=0;i<SLOTS;i++){
    var hour = START_HOUR + Math.floor(i/2);
    var min = (i%2) * 30;
    if(min === 0){
      var label = hour === 0 ? '12 AM' : hour < 12 ? hour+' AM' : hour === 12 ? '12 PM' : (hour-12)+' PM';
      th += '<div class="sc-time-label">'+label+'</div>';
    } else {
      th += '<div class="sc-time-label"></div>';
    }
  }
  timesEl.innerHTML = th;
  
  // Day columns with slots
  var today = fmtDate(new Date());
  var ch = '';
  days.forEach(function(d, di){
    var isToday = fmtDate(d) === today;
    ch += '<div class="sc-col'+(isToday?' today':'')+'" data-date="'+fmtDate(d)+'" data-idx="'+di+'">';
    for(var s=0;s<SLOTS;s++){
      var hour = START_HOUR + Math.floor(s/2);
      var min = (s%2)*30;
      var timeStr = pad(hour)+':'+pad(min);
      ch += '<div class="sc-slot" data-time="'+timeStr+'" data-date="'+fmtDate(d)+'"></div>';
    }
    ch += '</div>';
  });
  colsEl.innerHTML = ch;
  
  // Click on empty slot to create session
  colsEl.querySelectorAll('.sc-slot').forEach(function(slot){
    slot.addEventListener('click', function(){
      openNewModal(this.dataset.date, this.dataset.time);
    });
  });
  
  // Now indicator
  updateNowLine();
}

function updateNowLine(){
  var line = document.getElementById('scNow');
  if(!line) return;
  var now = new Date();
  var mins = now.getHours() * 60 + now.getMinutes();
  var startMins = START_HOUR * 60;
  var endMins = END_HOUR * 60;
  if(mins < startMins || mins > endMins){
    line.style.display = 'none';
    return;
  }
  var pct = (mins - startMins) / (endMins - startMins);
  var totalH = SLOTS * SLOT_H;
  line.style.display = 'block';
  line.style.top = (56 + pct * totalH) + 'px'; // 56 = header height approx
}

// ===== Load Events =====
function loadEvents(days){
  if(!days) days = getDays();
  var start = fmtDate(days[0]);
  var end = fmtDate(days[days.length-1]);
  
  var params = 'action=ptp_schedule_get_events&nonce='+PTPSchedule.nonce;
  params += '&start='+start+'&end='+end;
  if(selectedTrainers.length) params += '&trainers='+selectedTrainers.join(',');
  
  jQuery.get(PTPSchedule.ajax + '?' + params, function(r){
    if(r.success){
      events = r.data || [];
    } else {
      events = Array.isArray(r) ? r : [];
    }
    renderEvents(days);
  }).fail(function(){
    events = [];
    renderEvents(days);
  });
}

function renderEvents(days){
  // Clear existing events
  document.querySelectorAll('.sc-event').forEach(function(e){ e.remove(); });
  
  // Count events per trainer for sidebar
  var trainerCounts = {};
  events.forEach(function(ev){
    var tid = ev.extendedProps ? ev.extendedProps.trainer_id : null;
    if(tid) trainerCounts[tid] = (trainerCounts[tid] || 0) + 1;
  });
  document.querySelectorAll('.sc-trainer').forEach(function(row){
    var id = row.dataset.id;
    var cnt = row.querySelector('.sc-trainer-cnt');
    if(cnt) cnt.textContent = trainerCounts[id] || 0;
  });
  
  if(!events.length) return;
  
  var dayMap = {};
  days.forEach(function(d, i){
    dayMap[fmtDate(d)] = i;
  });
  
  events.forEach(function(ev){
    var dateStr = ev.start ? ev.start.substring(0,10) : '';
    var colIdx = dayMap[dateStr];
    if(colIdx === undefined) return;
    
    // Check trainer filter
    var tid = ev.extendedProps ? ev.extendedProps.trainer_id : null;
    if(tid && selectedTrainers.length && selectedTrainers.indexOf(parseInt(tid)) === -1) return;
    
    var col = document.querySelector('.sc-col[data-idx="'+colIdx+'"]');
    if(!col) return;
    
    // Parse start/end times
    var startTime = ev.start ? ev.start.substring(11,16) : '09:00';
    var endTime = ev.end ? ev.end.substring(11,16) : '10:00';
    
    var startMins = timeToMins(startTime);
    var endMins = timeToMins(endTime);
    if(endMins <= startMins) endMins = startMins + 60;
    
    var gridStart = START_HOUR * 60;
    var topPx = ((startMins - gridStart) / 30) * SLOT_H;
    var heightPx = ((endMins - startMins) / 30) * SLOT_H;
    if(heightPx < 20) heightPx = 20;
    
    var status = ev.extendedProps ? ev.extendedProps.session_status : 'scheduled';
    var trainerName = ev.extendedProps ? ev.extendedProps.trainer_name : '';
    var playerName = ev.extendedProps ? ev.extendedProps.player_name : '';
    var tColor = tid ? (trainerColors[tid] || '#3b82f6') : '#3b82f6';
    
    var block = document.createElement('div');
    block.className = 'sc-event st-' + status;
    block.style.top = topPx + 'px';
    block.style.height = heightPx + 'px';
    block.style.borderColor = tColor;
    block.dataset.id = ev.id || '';
    
    var showSub = heightPx > 36;
    block.innerHTML = '<div class="ev-time">' + fmtTime12(startTime) + ' - ' + fmtTime12(endTime) + '</div>'
      + '<div class="ev-title">' + esc(playerName || 'Session') + '</div>'
      + (showSub ? '<div class="ev-trainer">' + esc(trainerName) + '</div>' : '')
      + '<div class="ev-status"></div>';
    
    block.addEventListener('click', function(e){
      e.stopPropagation();
      openDetailModal(ev);
    });
    
    col.appendChild(block);
  });
}

// ===== Mini Calendar =====
function renderMiniCal(){
  var grid = document.getElementById('scMiniGrid');
  var title = document.getElementById('scMiniTitle');
  if(!grid) return;
  
  title.textContent = MONTHS[miniMonth].substr(0,3) + ' ' + miniYear;
  
  var first = new Date(miniYear, miniMonth, 1);
  var startDow = first.getDay(); // 0=Sun
  var daysInMonth = new Date(miniYear, miniMonth+1, 0).getDate();
  var today = fmtDate(new Date());
  
  // Get current week range for highlighting
  var weekDays = getDays();
  var weekSet = {};
  weekDays.forEach(function(d){ weekSet[fmtDate(d)] = true; });
  
  var h = '';
  ['S','M','T','W','T','F','S'].forEach(function(d){ h += '<div class="dow">'+d+'</div>'; });
  
  // Fill blanks before first day
  for(var i=0;i<startDow;i++){
    var prev = new Date(miniYear, miniMonth, -(startDow-1-i));
    h += '<div class="day other" data-d="'+fmtDate(prev)+'">'+prev.getDate()+'</div>';
  }
  
  for(var d=1;d<=daysInMonth;d++){
    var dt = new Date(miniYear, miniMonth, d);
    var ds = fmtDate(dt);
    var cls = 'day';
    if(ds === today) cls += ' today';
    else if(weekSet[ds]) cls += ' in-week';
    h += '<div class="'+cls+'" data-d="'+ds+'">'+d+'</div>';
  }
  
  // Fill remaining days
  var total = startDow + daysInMonth;
  var remaining = total % 7 === 0 ? 0 : 7 - (total % 7);
  for(var i=1;i<=remaining;i++){
    var next = new Date(miniYear, miniMonth+1, i);
    h += '<div class="day other" data-d="'+fmtDate(next)+'">'+i+'</div>';
  }
  
  grid.innerHTML = h;
  
  // Click on day
  grid.querySelectorAll('.day').forEach(function(el){
    el.addEventListener('click', function(){
      currentDate = parseDate(this.dataset.d);
      renderCalendar();
      renderMiniCal();
    });
  });
}

function bindMiniNav(){
  var prev = document.getElementById('scMiniPrev');
  var next = document.getElementById('scMiniNext');
  if(prev) prev.onclick = function(){
    miniMonth--;
    if(miniMonth < 0){ miniMonth = 11; miniYear--; }
    renderMiniCal();
  };
  if(next) next.onclick = function(){
    miniMonth++;
    if(miniMonth > 11){ miniMonth = 0; miniYear++; }
    renderMiniCal();
  };
}

// ===== Trainer Filters =====
function bindTrainers(){
  document.querySelectorAll('.sc-trainer').forEach(function(row){
    row.addEventListener('click', function(){
      var id = parseInt(this.dataset.id);
      var idx = selectedTrainers.indexOf(id);
      if(idx > -1){
        selectedTrainers.splice(idx, 1);
        this.classList.add('off');
      } else {
        selectedTrainers.push(id);
        this.classList.remove('off');
      }
      loadEvents();
    });
  });
  
  var allBtn = document.getElementById('scTrainersAll');
  if(allBtn) allBtn.onclick = function(){
    selectedTrainers = [];
    document.querySelectorAll('.sc-trainer').forEach(function(r){
      selectedTrainers.push(parseInt(r.dataset.id));
      r.classList.remove('off');
    });
    loadEvents();
  };
  
  bindMiniNav();
}

// ===== Stats =====
function loadStats(){
  jQuery.post(PTPSchedule.ajax, {
    action: 'ptp_schedule_get_dashboard_stats',
    nonce: PTPSchedule.nonce
  }, function(r){
    if(!r.success) return;
    var d = r.data;
    setText('scStatToday', d.today || 0);
    setText('scStatWeek', d.week || 0);
    setText('scStatPending', d.pending || 0);
    setText('scStatRevenue', '$'+Math.round(d.revenue || 0));
  });
}

// ===== New Session Modal =====
function bindNewSession(){
  var btn = document.getElementById('scNewBtn');
  if(btn) btn.onclick = function(){
    var today = fmtDate(new Date());
    openNewModal(today, '09:00');
  };
}

function openNewModal(date, time){
  var ov = document.getElementById('scNewOverlay');
  if(!ov) return;
  
  // Reset form
  var form = document.getElementById('scNewForm');
  if(form) form.reset();
  
  setText('scNewTitle', 'New Session');
  setVal('scNewDate', date);
  setVal('scNewTime', time);
  setVal('scNewDuration', '60');
  setVal('scNewStatus', 'scheduled');
  setVal('scNewId', '');
  
  ov.classList.add('open');
}

function openDetailModal(ev){
  var ov = document.getElementById('scDetailOverlay');
  if(!ov) return;
  
  var p = ev.extendedProps || {};
  var startTime = ev.start ? ev.start.substring(11,16) : '';
  var endTime = ev.end ? ev.end.substring(11,16) : '';
  var dateStr = ev.start ? ev.start.substring(0,10) : '';
  
  var body = document.getElementById('scDetailBody');
  if(!body) return;
  
  var statusBadge = '<span class="sc-badge sc-badge-'+(p.session_status||'scheduled')+'">'+(p.session_status||'scheduled')+'</span>';
  var payBadge = '<span class="sc-badge sc-badge-'+(p.payment_status||'unpaid')+'">'+(p.payment_status||'unpaid')+'</span>';
  
  var h = '<div class="sc-detail-section">';
  h += '<div class="sc-detail-title">Session</div>';
  h += R('Player', esc(p.player_name || 'N/A') + (p.player_age ? ' (age '+p.player_age+')' : ''));
  h += R('Trainer', esc(p.trainer_name || 'Unassigned'));
  h += R('Date', formatDateNice(dateStr));
  h += R('Time', fmtTime12(startTime) + ' - ' + fmtTime12(endTime));
  h += R('Type', (p.session_type || '1on1').replace('_',' '));
  h += R('Status', statusBadge);
  h += '</div>';
  
  h += '<div class="sc-detail-section">';
  h += '<div class="sc-detail-title">Payment</div>';
  h += R('Amount', '$' + (parseFloat(p.price)||0).toFixed(2));
  h += R('Payment', payBadge);
  h += '</div>';
  
  if(p.location_text){
    h += '<div class="sc-detail-section">';
    h += '<div class="sc-detail-title">Location</div>';
    h += R('', esc(p.location_text));
    h += '</div>';
  }
  
  if(p.internal_notes){
    h += '<div class="sc-detail-section">';
    h += '<div class="sc-detail-title">Notes</div>';
    h += '<div style="padding:0 0 4px;font-size:13px;color:#374151">' + esc(p.internal_notes) + '</div>';
    h += '</div>';
  }
  
  if(p.customer_name || p.customer_email){
    h += '<div class="sc-detail-section">';
    h += '<div class="sc-detail-title">Parent</div>';
    if(p.customer_name) h += R('Name', esc(p.customer_name));
    if(p.customer_email) h += R('Email', '<a href="mailto:'+esc(p.customer_email)+'">'+esc(p.customer_email)+'</a>');
    h += '</div>';
  }
  
  body.innerHTML = h;
  
  // Store event data for edit/delete
  ov.dataset.eventId = ev.id || '';
  ov.dataset.source = p.source || 'admin';
  ov.dataset.recordId = p.record_id || '';
  
  // Footer actions
  var foot = document.getElementById('scDetailFoot');
  if(foot){
    var fh = '';
    if(p.session_status !== 'completed' && p.session_status !== 'cancelled'){
      fh += '<button type="button" class="sc-btn sc-btn-green" onclick="scQuickStatus(\'confirmed\')">Confirm</button>';
      fh += '<button type="button" class="sc-btn sc-btn-outline" onclick="scQuickStatus(\'completed\')">Complete</button>';
    }
    fh += '<button type="button" class="sc-btn sc-btn-outline" onclick="scEditFromDetail()">Edit</button>';
    if(p.source === 'admin'){
      fh += '<button type="button" class="sc-btn sc-btn-red" onclick="scDeleteSession()">Delete</button>';
    }
    foot.innerHTML = fh;
  }
  
  ov.classList.add('open');
}

// ===== Quick Status =====
window.scQuickStatus = function(newStatus){
  var ov = document.getElementById('scDetailOverlay');
  if(!ov) return;
  
  var source = ov.dataset.source;
  var recordId = ov.dataset.recordId;
  if(!recordId) return;
  
  var fullId = (source === 'booking' ? 'booking_' : 'session_') + recordId;
  
  jQuery.post(PTPSchedule.ajax, {
    action: 'ptp_schedule_quick_status',
    nonce: PTPSchedule.nonce,
    source: source,
    record_id: recordId,
    id: fullId,
    status: newStatus
  }, function(r){
    if(r.success){
      toast(newStatus.charAt(0).toUpperCase()+newStatus.slice(1)+' successfully');
      ov.classList.remove('open');
      loadEvents();
      loadStats();
    } else {
      toast('Error: '+(r.data||'Failed'), true);
    }
  });
};

// ===== Edit from Detail =====
window.scEditFromDetail = function(){
  var ov = document.getElementById('scDetailOverlay');
  if(!ov) return;
  
  // Find the event data
  var evId = ov.dataset.eventId;
  var ev = events.find(function(e){ return e.id === evId; });
  if(!ev) return;
  
  ov.classList.remove('open');
  
  var p = ev.extendedProps || {};
  var startTime = ev.start ? ev.start.substring(11,16) : '09:00';
  var dateStr = ev.start ? ev.start.substring(0,10) : fmtDate(new Date());
  
  setText('scNewTitle', 'Edit Session');
  setVal('scNewId', ev.id || '');
  setVal('scNewTrainer', p.trainer_id || '');
  setVal('scNewDate', dateStr);
  setVal('scNewTime', startTime);
  setVal('scNewDuration', p.duration_minutes || '60');
  setVal('scNewPlayer', p.player_name || '');
  setVal('scNewAge', p.player_age || '');
  setVal('scNewStatus', p.session_status || 'scheduled');
  setVal('scNewPayment', p.payment_status || 'unpaid');
  setVal('scNewPrice', p.price || '');
  setVal('scNewLocation', p.location_text || '');
  setVal('scNewNotes', p.internal_notes || '');
  setVal('scNewType', p.session_type || '1on1');
  
  document.getElementById('scNewOverlay').classList.add('open');
};

// ===== Delete Session =====
window.scDeleteSession = function(){
  var ov = document.getElementById('scDetailOverlay');
  if(!ov) return;
  
  if(!confirm('Delete this session?')) return;
  
  var recordId = ov.dataset.recordId;
  var source = ov.dataset.source;
  if(!recordId || source !== 'admin') return;
  
  var fullId = (source === 'booking' ? 'booking_' : 'session_') + recordId;
  
  jQuery.post(PTPSchedule.ajax, {
    action: 'ptp_schedule_delete_session',
    nonce: PTPSchedule.nonce,
    id: fullId
  }, function(r){
    if(r.success){
      toast('Session deleted');
      ov.classList.remove('open');
      loadEvents();
      loadStats();
    } else {
      toast('Error: '+(r.data ? r.data.message : 'Failed'), true);
    }
  });
};

// ===== Save Session =====
window.scSaveSession = function(){
  var form = document.getElementById('scNewForm');
  if(!form) return;
  
  var data = {
    action: document.getElementById('scNewId').value ? 'ptp_schedule_update_session' : 'ptp_schedule_create_session',
    nonce: PTPSchedule.nonce,
    id: document.getElementById('scNewId').value,
    trainer_id: getVal('scNewTrainer'),
    session_date: getVal('scNewDate'),
    start_time: getVal('scNewTime'),
    duration_minutes: getVal('scNewDuration'),
    player_name: getVal('scNewPlayer'),
    player_age: getVal('scNewAge'),
    session_status: getVal('scNewStatus'),
    payment_status: getVal('scNewPayment'),
    price: getVal('scNewPrice'),
    location_text: getVal('scNewLocation'),
    internal_notes: getVal('scNewNotes'),
    session_type: getVal('scNewType')
  };
  
  if(!data.trainer_id || !data.session_date || !data.start_time){
    toast('Please fill trainer, date, and time', true);
    return;
  }
  
  var btn = document.getElementById('scSaveBtn');
  if(btn){ btn.disabled = true; btn.innerHTML = '<span class="sc-spin"></span> Saving...'; }
  
  jQuery.post(PTPSchedule.ajax, data, function(r){
    if(btn){ btn.disabled = false; btn.textContent = 'Save Session'; }
    if(r.success){
      toast(data.id ? 'Session updated' : 'Session created');
      document.getElementById('scNewOverlay').classList.remove('open');
      loadEvents();
      loadStats();
    } else {
      toast('Error: '+(r.data ? (r.data.message || r.data) : 'Failed'), true);
    }
  }).fail(function(){
    if(btn){ btn.disabled = false; btn.textContent = 'Save Session'; }
    toast('Network error', true);
  });
};

// ===== Close Modals =====
window.scCloseNew = function(){ document.getElementById('scNewOverlay').classList.remove('open'); };
window.scCloseDetail = function(){ document.getElementById('scDetailOverlay').classList.remove('open'); };

document.addEventListener('keydown', function(e){
  if(e.key === 'Escape'){
    document.querySelectorAll('.sc-overlay.open').forEach(function(o){ o.classList.remove('open'); });
  }
});

// ===== Helpers =====
function fmtDate(d){ return d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate()); }
function parseDate(s){ var p=s.split('-'); return new Date(parseInt(p[0]),parseInt(p[1])-1,parseInt(p[2])); }
function pad(n){ return n<10?'0'+n:''+n; }
function timeToMins(t){ var p=t.split(':'); return parseInt(p[0])*60+parseInt(p[1]); }
function fmtTime12(t){
  if(!t) return '';
  var p=t.split(':');
  var h=parseInt(p[0]),m=p[1]||'00';
  var ap=h>=12?'pm':'am';
  if(h===0) h=12; else if(h>12) h-=12;
  return h+':'+m+ap;
}
function formatDateNice(s){
  if(!s) return '';
  var d = parseDate(s);
  return DOW[d.getDay()] + ', ' + MONTHS[d.getMonth()].substr(0,3) + ' ' + d.getDate() + ', ' + d.getFullYear();
}
function esc(s){ return (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function setText(id,v){ var e=document.getElementById(id); if(e) e.textContent=v; }
function setVal(id,v){ var e=document.getElementById(id); if(e) e.value=v||''; }
function getVal(id){ var e=document.getElementById(id); return e?e.value:''; }
function R(l,v){ return '<div class="sc-detail-row">'+(l?'<div class="dl">'+l+'</div>':'')+'<div class="dv">'+v+'</div></div>'; }
function toast(msg, isErr){
  var t = document.getElementById('scToast');
  if(!t) return;
  t.textContent = msg;
  t.style.background = isErr ? '#dc2626' : '#111827';
  t.classList.add('show');
  setTimeout(function(){ t.classList.remove('show'); }, 2500);
}

// Now line updater
setInterval(updateNowLine, 60000);

})();
