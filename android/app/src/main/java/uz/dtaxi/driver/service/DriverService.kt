package uz.dtaxi.driver.service

import kotlinx.coroutines.flow.drop
import kotlinx.coroutines.flow.distinctUntilChanged
import kotlinx.coroutines.flow.map
import android.Manifest
import android.annotation.SuppressLint
import android.app.Notification
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.pm.PackageManager
import androidx.core.content.ContextCompat
import android.content.Context
import android.content.Intent
import android.content.pm.ServiceInfo
import android.location.Location
import android.os.Looper
import androidx.core.app.NotificationCompat
import androidx.core.app.ServiceCompat
import androidx.lifecycle.LifecycleService
import androidx.lifecycle.lifecycleScope
import com.google.android.gms.location.LocationCallback
import com.google.android.gms.location.LocationRequest
import com.google.android.gms.location.LocationResult
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import com.google.gson.Gson
import com.google.gson.JsonObject
import com.google.gson.reflect.TypeToken
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.collectLatest
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import uz.dtaxi.driver.DtaxiApp
import uz.dtaxi.driver.R
import uz.dtaxi.driver.data.DriverState
import uz.dtaxi.driver.data.IncomingCall
import uz.dtaxi.driver.data.LocationPoint
import uz.dtaxi.driver.data.Offer
import uz.dtaxi.driver.data.Order
import uz.dtaxi.driver.data.Result
import uz.dtaxi.driver.taximeter.MeterState
import java.io.File
import uz.dtaxi.driver.realtime.Realtime
import uz.dtaxi.driver.taximeter.ServiceArea
import uz.dtaxi.driver.taximeter.Taximeter
import uz.dtaxi.driver.ui.MainActivity

/**
 * Liniyadagi haydovchi (reja §8.4): foreground service — GPS (internet uzilsa ham yig'iladi),
 * Reverb ulanishi, takliflar va taksometr. Ilova yopilsa ham ishlaydi.
 */
class DriverService : LifecycleService() {
    private val app get() = application as DtaxiApp
    private val buffer = LocationBuffer()
    private var realtime: Realtime? = null
    private var taximeter: Taximeter? = null
    private var meterOrderId: Int? = null
    private var flushJob: Job? = null
    /** Foreground holatiga o'tdi va joylashuvga ruxsat bor — aks holda servis darhol to'xtaydi */
    private var foreground = false
    /** Server /orders/current javobini bergunga qadar "buyurtma yo'q" (null) taksometrni o'chirmasin */
    private var orderSynced = false
    /** Turgan bo'sh haydovchi uchun siyrak GPS (batareya) */
    private var slowGps = false
    private var lastBufferedAt = 0L
    private var lastMovedAt = System.currentTimeMillis()
    private var lastMovedLat = 0.0
    private var lastMovedLng = 0.0
    /** Oxirgi GPS nuqtasi vaqti — tizim qayta tug'dirgan servis joylashuv olmayotganini aniqlash uchun */
    @Volatile private var lastFixAt = System.currentTimeMillis()
    private var pausedShown = false

    private val locationCallback = object : LocationCallback() {
        override fun onLocationResult(result: LocationResult) {
            result.locations.forEach(::onLocation)
        }
    }

    // Bildirishnoma matnlari ilova tilida
    override fun attachBaseContext(base: android.content.Context) {
        super.attachBaseContext(uz.dtaxi.driver.data.AppLocale.wrap(base, DtaxiApp.instance.session.locale))
    }

    override fun onCreate() {
        super.onCreate()
        foreground = enterForeground()
        if (!foreground) {
            // Joylashuvsiz liniyada turish ma'nosiz — haydovchi bildirishnomani bosib ilovani ochadi va davom etadi
            showPaused()
            stopSelf()
            return
        }
        loadBuffer()
        lifecycleScope.launch {
            // Tizim servisni o'zi qayta ishga tushirsa ekran ochilmagan — profilni servis o'zi yuklaydi
            if (!ensureProfile()) {
                stopSelf()
                return@launch
            }
            restoreTaximeter()
            startLocation()
            connectRealtime()
            flushJob = launch { flushLoop() }
            launch { syncLoop() }
            launch { gpsWatchdog() }
            // Liniyaga chiqqanda operator xaritasida darhol ko'rinsin (keyingi siyrak GPS'ni kutmasdan)
            launch {
                DriverState.profile.map { it?.status }.distinctUntilChanged().drop(1).collect { status ->
                    if (status == "free") wentOnline()
                }
            }
            DriverState.order.collectLatest(::onOrderChanged)
        }
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        super.onStartCommand(intent, flags, startId)
        if (!foreground) return START_NOT_STICKY
        // Ilova ochilib servis qayta chaqirildi (oldinda) — fonda qayta tug'ilgan servis joylashuvni endi oladi
        if (intent != null && startId > 1 && enterForeground() && DriverState.profile.value != null) startLocation()
        return START_STICKY
    }

    /**
     * Android 14: fonda (START_STICKY qayta ishga tushirish) yoki ruxsatsiz location turidagi servis SecurityException
     * beradi; 11-13 da esa joylashuv kelmaydi — ruxsat yo'q bo'lsa servis to'xtaydi.
     */
    private fun enterForeground(): Boolean {
        val result = runCatching {
            ServiceCompat.startForeground(this, NOTIFICATION_ID, notification(), ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION)
        }
        result.exceptionOrNull()?.let { android.util.Log.w("DriverService", "Foreground servis ishga tushmadi", it) }
        return result.isSuccess && hasLocationPermission(this)
    }

    /** "Liniya to'xtatildi — davom ettirish uchun bosing" */
    private fun showPaused() {
        val paused = NotificationCompat.Builder(this, DtaxiApp.CHANNEL_MESSAGES)
            .setSmallIcon(R.drawable.ic_notification)
            .setContentTitle(getString(R.string.service_paused_title))
            .setContentText(getString(R.string.service_paused_text))
            .setAutoCancel(true)
            .setContentIntent(
                PendingIntent.getActivity(
                    this,
                    2,
                    Intent(this, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK),
                    PendingIntent.FLAG_IMMUTABLE,
                ),
            )
            .build()
        runCatching { getSystemService(NotificationManager::class.java).notify(PAUSED_NOTIFICATION_ID, paused) }
    }

    private suspend fun ensureProfile(): Boolean {
        var wait = 2_000L
        while (DriverState.profile.value == null) {
            if (app.session.token == null) return false
            val result = app.repository.refreshProfile()
            if (result is Result.Error && result.code == 401) return false
            if (result is Result.Ok) break
            delay(wait)
            wait = (wait * 2).coerceAtMost(60_000L)
        }
        return true
    }

    /**
     * Zaxira sinxronlash: Reverb xabari yo'qolgan bo'lsa ham holat 30 soniyada tiklanadi;
     * internet yo'q paytda bosilgan "Yakunlash" ham shu yerda qayta yuboriladi.
     */
    private suspend fun syncLoop() {
        while (lifecycleScope.isActive) {
            app.repository.retryPendingComplete()
            if (app.repository.refreshOrder() is Result.Ok) {
                orderSynced = true
                if (DriverState.order.value == null) onOrderChanged(null)
            }
            delay(if (app.session.pendingComplete != null) 10_000L else 30_000L)
        }
    }

    /**
     * Android 11-13: tizim servisni fonda qayta tug'dirsa u ishlaydi, lekin joylashuv kelmaydi.
     * GPS uzoq kelmasa haydovchiga "davom ettirish uchun bosing" chiqadi — ilova ochilganda joylashuv qayta yoqiladi.
     */
    private suspend fun gpsWatchdog() {
        while (lifecycleScope.isActive) {
            delay(30_000L)
            val silent = System.currentTimeMillis() - lastFixAt > GPS_SILENCE_MS
            if (silent && !pausedShown) {
                showPaused()
                pausedShown = true
            }
        }
    }

    override fun onDestroy() {
        if (foreground) {
            runCatching { LocationServices.getFusedLocationProviderClient(this).removeLocationUpdates(locationCallback) }
            realtime?.disconnect()
            DriverState.setRealtimeConnected(true)
            flushJob?.cancel()
            // Ishga tushmagan servis diskdagi bufferni bo'sh ro'yxat bilan o'chirib yubormasin
            saveBuffer()
        }
        super.onDestroy()
    }

    @SuppressLint("MissingPermission")
    private fun startLocation() {
        val configured = (DriverState.profile.value?.config?.locationIntervalSeconds ?: 4) * 1000L
        // Buyurtma paytida navigator xaritasi silliq yursin — har soniyada (serverga baribir siyrak ketadi)
        val interval = when {
            hasActiveOrder() -> NAV_INTERVAL_MS
            slowGps -> maxOf(configured, SLOW_INTERVAL_MS)
            else -> configured
        }
        val request = LocationRequest.Builder(Priority.PRIORITY_HIGH_ACCURACY, interval)
            .setMinUpdateIntervalMillis(interval / 2)
            .build()
        runCatching {
            val client = LocationServices.getFusedLocationProviderClient(this)
            client.removeLocationUpdates(locationCallback)
            client.requestLocationUpdates(request, locationCallback, Looper.getMainLooper())
        }
    }

    private fun onLocation(location: Location) {
        lastFixAt = System.currentTimeMillis()
        if (pausedShown) {
            pausedShown = false
            runCatching { getSystemService(NotificationManager::class.java).cancel(PAUSED_NOTIFICATION_ID) }
        }
        // Soxta GPS ilovasi (mock location): server nuqtani hisobga olmaydi, taksometr ham
        @Suppress("DEPRECATION")
        val mock = if (android.os.Build.VERSION.SDK_INT >= 31) location.isMock else location.isFromMockProvider
        if (!mock) {
            DriverState.setLocation(location.latitude, location.longitude)
            // Turganda GPS yo'nalishi tasodifiy — faqat harakatda yangilanadi
            DriverState.setMotion(if (location.hasBearing() && location.speed > 1.5f) location.bearing else null, (location.speed * 3.6).toInt())
        }
        val configuredMs = (DriverState.profile.value?.config?.locationIntervalSeconds ?: 4) * 1000L
        if (location.time - lastBufferedAt >= configuredMs - 500) {
            lastBufferedAt = location.time
            buffer.add(
                LocationPoint(
                    location.latitude,
                    location.longitude,
                    (location.speed * 3.6).toInt(),
                    location.bearing.toInt(),
                    location.accuracy.toInt(),
                    mock,
                    location.time,
                ),
            )
        }
        if (mock) return
        adaptInterval(location)

        val order = DriverState.order.value
        val meter = taximeter
        if (order?.status == "in_progress" && meter != null && meterOrderId == order.id) {
            if (meter.add(location.latitude, location.longitude, location.accuracy, location.time)) {
                DriverState.setMeters(meter.insideMeters.toLong(), meter.outsideMeters.toLong())
                saveTaximeter(meter.snapshot(order.id))
            }
        }
    }

    /** Bo'sh va 2 daqiqadan beri joyidan qimirlamagan haydovchi — GPS siyrakroq; harakat yoki buyurtma — odatdagi */
    private fun adaptInterval(location: Location) {
        val now = System.currentTimeMillis()
        val moved = location.speed > 1.5f ||
            uz.dtaxi.driver.taximeter.Geo.distance(lastMovedLat, lastMovedLng, location.latitude, location.longitude) > 30
        if (moved) {
            lastMovedAt = now
            lastMovedLat = location.latitude
            lastMovedLng = location.longitude
        }
        val idle = DriverState.profile.value?.status == "free" && DriverState.order.value == null && now - lastMovedAt > 2 * 60_000L
        if (idle != slowGps) {
            slowGps = idle
            startLocation()
        }
    }

    @SuppressLint("MissingPermission")
    private fun wentOnline() {
        lastMovedAt = System.currentTimeMillis()
        lastBufferedAt = 0L
        if (slowGps) {
            slowGps = false
            startLocation()
        }
        runCatching {
            LocationServices.getFusedLocationProviderClient(this)
                .getCurrentLocation(Priority.PRIORITY_HIGH_ACCURACY, null)
                .addOnSuccessListener { location ->
                    location?.let(::onLocation)
                    lifecycleScope.launch { sendBuffered() }
                }
        }
    }

    /** Eskisidan boshlab, bir so'rovda 200 tadan */
    private suspend fun sendBuffered() {
        while (!buffer.isEmpty()) {
            val batch = buffer.batch(200)
            if (!app.repository.sendLocations(batch)) break
            buffer.drop(batch.size)
        }
    }

    private suspend fun flushLoop() {
        var unsaved = 0
        while (lifecycleScope.isActive) {
            delay((DriverState.profile.value?.config?.locationIntervalSeconds ?: 4) * 1000L)
            sendBuffered()
            // Internet yo'q — nuqtalar vaqti-vaqti bilan diskka (servis o'ldirilsa ham trek yo'qolmasin)
            if (++unsaved >= 8 || buffer.isEmpty()) {
                saveBuffer()
                unsaved = 0
            }
        }
    }

    private fun connectRealtime() {
        val profile = DriverState.profile.value ?: return
        val token = app.session.token ?: return
        realtime = Realtime(profile.config.reverb, token, profile.id, ::onEvent) { connected ->
            DriverState.setRealtimeConnected(connected)
            // Uzilish paytida kelgan xabarlar yo'qolgan — holatni serverdan qayta olamiz
            if (connected) lifecycleScope.launch { app.repository.refreshOrder() }
        }.also { it.connect() }
    }

    /** Pusher oqimida chaqiriladi: noto'g'ri xabar butun servisni yiqitmasin (keyingi sinxronlash holatni tiklaydi) */
    private fun onEvent(name: String, data: JsonObject) {
        runCatching { handleEvent(name, data) }
            .onFailure { android.util.Log.w("DriverService", "Reverb xabari o'qilmadi: $name", it) }
    }

    private fun handleEvent(name: String, data: JsonObject) {
        when (name) {
            "OrderOffered" -> {
                val offer = gson.fromJson(data, Offer::class.java) ?: return
                if (DriverState.offer(offer)) OfferNotifier.show(this, offer)
            }
            "OfferWithdrawn" -> {
                DriverState.clearOffer(data.get("order_id")?.takeIf { !it.isJsonNull }?.asInt)
                if (DriverState.offer.value == null) OfferNotifier.cancel(this)
            }
            "OrderUpdated" -> {
                val json = data.get("order")?.takeIf { it.isJsonObject } ?: return
                val order = gson.fromJson(json, Order::class.java) ?: return
                DriverState.setOrder(order)
                if (order.status in DriverState.FINISHED) lifecycleScope.launch { app.repository.refreshProfile() }
            }
            "IncomingCall" -> {
                val callId = data.get("call_id")?.takeIf { !it.isJsonNull }?.asInt ?: return
                val operator = data.get("operator")?.takeIf { !it.isJsonNull }?.asString.orEmpty()
                val expiresIn = data.get("expires_in")?.takeIf { !it.isJsonNull }?.asInt ?: 30
                IncomingCallNotifier.show(this, IncomingCall(callId, operator, expiresIn))
            }
            "IncomingCallEnded" -> data.get("call_id")?.takeIf { !it.isJsonNull }?.asInt?.let { IncomingCallNotifier.ended(this, it) }
            "ChatMessageSent" -> {
                // O'zimiz yuborgan xabar ham shu kanalga keladi — faqat dispetcherdan kelganlar sanaladi
                if (data.get("sender_id")?.takeIf { !it.isJsonNull }?.asInt != DriverState.profile.value?.userId) {
                    DriverState.incrementUnread()
                    data.get("body")?.takeIf { !it.isJsonNull }?.asString?.let { OfferNotifier.message(this, it) }
                }
            }
            // Dispetcher bizning xabarlarimizni o'qidi (haydovchi o'qigani — operatorlar uchun)
            "ChatMessagesRead" -> if (data.get("by_driver")?.asBoolean == false) {
                data.get("up_to_id")?.takeIf { !it.isJsonNull }?.asInt?.let(DriverState::chatRead)
            }
        }
    }

    /** Safar boshlanganda taksometr noldan; tugaganda tozalanadi */
    private fun hasActiveOrder() = DriverState.order.value?.status in setOf("assigned", "arrived", "in_progress")

    private var navGps = false

    private fun onOrderChanged(order: Order?) {
        if (hasActiveOrder() != navGps) {
            navGps = hasActiveOrder()
            startLocation()
        }
        if (order == null) {
            // Ilk null — hali serverdan javob kelmagan; saqlangan taksometrni o'chirmaymiz
            if (!orderSynced || app.session.pendingComplete != null) return
            clearTaximeter()
            return
        }
        if (order.status == "in_progress" && meterOrderId != order.id) {
            taximeter = Taximeter(ServiceArea(DriverState.profile.value?.config?.serviceArea))
            meterOrderId = order.id
            DriverState.setMeters(0, 0)
        } else if (order.status != "in_progress" && meterOrderId != null && meterOrderId != order.id) {
            clearTaximeter()
        }
    }

    private fun clearTaximeter() {
        taximeter = null
        meterOrderId = null
        DriverState.setMeters(0, 0)
        prefs().edit().clear().apply()
    }

    /** Jarayon o'ldirilsa ham taksometr yo'qolmasin */
    private fun saveTaximeter(state: MeterState) {
        prefs().edit().putString("state", gson.toJson(state)).apply()
    }

    private fun restoreTaximeter() {
        val state = prefs().getString("state", null)
            ?.let { runCatching { gson.fromJson(it, MeterState::class.java) }.getOrNull() } ?: return
        taximeter = Taximeter(ServiceArea(DriverState.profile.value?.config?.serviceArea)).also { it.restore(state) }
        meterOrderId = state.orderId
        DriverState.setMeters(state.inside.toLong(), state.outside.toLong())
    }

    private fun bufferFile() = File(filesDir, "gps-buffer.json")

    private fun saveBuffer() {
        runCatching {
            if (buffer.isEmpty()) bufferFile().delete() else bufferFile().writeText(gson.toJson(buffer.toList()))
        }
    }

    private fun loadBuffer() {
        val saved = runCatching {
            gson.fromJson<List<LocationPoint>>(bufferFile().readText(), object : TypeToken<List<LocationPoint>>() {}.type)
        }.getOrNull() ?: return
        buffer.addAll(saved, System.currentTimeMillis())
    }

    private fun prefs() = getSharedPreferences("taximeter", Context.MODE_PRIVATE)

    private fun notification(): Notification = NotificationCompat.Builder(this, DtaxiApp.CHANNEL_SERVICE)
        .setSmallIcon(R.drawable.ic_notification)
        .setContentTitle(getString(R.string.service_title))
        .setContentText(getString(R.string.service_text))
        .setOngoing(true)
        .setContentIntent(
            PendingIntent.getActivity(this, 0, Intent(this, MainActivity::class.java), PendingIntent.FLAG_IMMUTABLE),
        )
        .build()

    companion object {
        private const val NOTIFICATION_ID = 1001
        private const val PAUSED_NOTIFICATION_ID = 1002
        private const val SLOW_INTERVAL_MS = 15_000L
        private const val NAV_INTERVAL_MS = 1_000L
        // Server 5 daqiqada oflayn qiladi — haydovchi undan oldin ogohlantiriladi
        private const val GPS_SILENCE_MS = 3 * 60_000L
        private val gson = Gson()

        /** Taxminiy (COARSE) ham yetadi — servis ishlaydi; aniq joylashuvni ekran alohida so'raydi */
        fun hasLocationPermission(context: Context) =
            ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED ||
                ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED

        /**
         * Ilova fonda bo'lsa (Android 12+) startForegroundService ForegroundServiceStartNotAllowedException beradi —
         * ilova yiqilmasin; false — servis ishga tushmadi.
         */
        fun start(context: Context): Boolean = runCatching {
            ContextCompat.startForegroundService(context, Intent(context, DriverService::class.java))
        }.onFailure { android.util.Log.w("DriverService", "Servis ishga tushmadi", it) }.isSuccess

        fun stop(context: Context) = context.stopService(Intent(context, DriverService::class.java))

        /** Hisobdan chiqishda: boshqa haydovchi kirsa eski trek va taksometr unga o'tmasin */
        fun clearLocalData(context: Context) {
            runCatching { File(context.filesDir, "gps-buffer.json").delete() }
            context.getSharedPreferences("taximeter", Context.MODE_PRIVATE).edit().clear().apply()
            DriverState.setMeters(0, 0)
        }
    }
}
