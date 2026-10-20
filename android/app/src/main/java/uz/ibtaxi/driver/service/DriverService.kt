package uz.ibtaxi.driver.service

import kotlinx.coroutines.flow.drop
import kotlinx.coroutines.flow.distinctUntilChanged
import kotlinx.coroutines.flow.map
import android.annotation.SuppressLint
import android.app.Notification
import android.app.PendingIntent
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
import uz.ibtaxi.driver.IbTaxiApp
import uz.ibtaxi.driver.R
import uz.ibtaxi.driver.data.DriverState
import uz.ibtaxi.driver.data.LocationPoint
import uz.ibtaxi.driver.data.Offer
import uz.ibtaxi.driver.data.Order
import uz.ibtaxi.driver.data.Result
import uz.ibtaxi.driver.taximeter.MeterState
import java.io.File
import uz.ibtaxi.driver.realtime.Realtime
import uz.ibtaxi.driver.taximeter.ServiceArea
import uz.ibtaxi.driver.taximeter.Taximeter
import uz.ibtaxi.driver.ui.MainActivity

/**
 * Liniyadagi haydovchi (reja §8.4): foreground service — GPS (internet uzilsa ham yig'iladi),
 * Reverb ulanishi, takliflar va taksometr. Ilova yopilsa ham ishlaydi.
 */
class DriverService : LifecycleService() {
    private val app get() = application as IbTaxiApp
    private val buffer = LocationBuffer()
    private var realtime: Realtime? = null
    private var taximeter: Taximeter? = null
    private var meterOrderId: Int? = null
    private var flushJob: Job? = null
    /** Server /orders/current javobini bergunga qadar "buyurtma yo'q" (null) taksometrni o'chirmasin */
    private var orderSynced = false
    /** Turgan bo'sh haydovchi uchun siyrak GPS (batareya) */
    private var slowGps = false
    private var lastBufferedAt = 0L
    private var lastMovedAt = System.currentTimeMillis()
    private var lastMovedLat = 0.0
    private var lastMovedLng = 0.0

    private val locationCallback = object : LocationCallback() {
        override fun onLocationResult(result: LocationResult) {
            result.locations.forEach(::onLocation)
        }
    }

    override fun onCreate() {
        super.onCreate()
        ServiceCompat.startForeground(this, NOTIFICATION_ID, notification(), ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION)
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
            // Liniyaga chiqqanda operator xaritasida darhol ko'rinsin (keyingi siyrak GPS'ni kutmasdan)
            launch {
                DriverState.profile.map { it?.status }.distinctUntilChanged().drop(1).collect { status ->
                    if (status == "free") wentOnline()
                }
            }
            DriverState.order.collectLatest(::onOrderChanged)
        }
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

    override fun onDestroy() {
        LocationServices.getFusedLocationProviderClient(this).removeLocationUpdates(locationCallback)
        realtime?.disconnect()
        DriverState.setRealtimeConnected(true)
        flushJob?.cancel()
        saveBuffer()
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
            uz.ibtaxi.driver.taximeter.Geo.distance(lastMovedLat, lastMovedLng, location.latitude, location.longitude) > 30
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

    private fun onEvent(name: String, data: JsonObject) {
        when (name) {
            "OrderOffered" -> {
                val offer = gson.fromJson(data, Offer::class.java)
                if (DriverState.offer(offer)) OfferNotifier.show(this, offer)
            }
            "OfferWithdrawn" -> {
                DriverState.clearOffer(data.get("order_id")?.asInt)
                OfferNotifier.cancel(this)
            }
            "OrderUpdated" -> {
                val order = gson.fromJson(data.getAsJsonObject("order"), Order::class.java)
                DriverState.setOrder(order)
                if (order.status in DriverState.FINISHED) lifecycleScope.launch { app.repository.refreshProfile() }
            }
            "ChatMessageSent" -> {
                // O'zimiz yuborgan xabar ham shu kanalga keladi — faqat dispetcherdan kelganlar sanaladi
                if (data.get("sender_id")?.asInt != DriverState.profile.value?.userId) {
                    DriverState.incrementUnread()
                    data.get("body")?.takeIf { !it.isJsonNull }?.asString?.let { OfferNotifier.message(this, it) }
                }
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

    private fun notification(): Notification = NotificationCompat.Builder(this, IbTaxiApp.CHANNEL_SERVICE)
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
        private const val SLOW_INTERVAL_MS = 15_000L
        private const val NAV_INTERVAL_MS = 1_000L
        private val gson = Gson()

        fun start(context: Context) = context.startForegroundService(Intent(context, DriverService::class.java))

        fun stop(context: Context) = context.stopService(Intent(context, DriverService::class.java))
    }
}
