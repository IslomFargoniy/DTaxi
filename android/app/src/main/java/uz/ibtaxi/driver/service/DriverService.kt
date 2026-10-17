package uz.ibtaxi.driver.service

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
    private val buffer = ArrayDeque<LocationPoint>()
    private var realtime: Realtime? = null
    private var taximeter: Taximeter? = null
    private var meterOrderId: Int? = null
    private var flushJob: Job? = null

    private val locationCallback = object : LocationCallback() {
        override fun onLocationResult(result: LocationResult) {
            result.locations.forEach(::onLocation)
        }
    }

    override fun onCreate() {
        super.onCreate()
        ServiceCompat.startForeground(this, NOTIFICATION_ID, notification(), ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION)
        restoreTaximeter()
        startLocation()
        connectRealtime()
        flushJob = lifecycleScope.launch { flushLoop() }
        lifecycleScope.launch { DriverState.order.collectLatest(::onOrderChanged) }
    }

    override fun onDestroy() {
        LocationServices.getFusedLocationProviderClient(this).removeLocationUpdates(locationCallback)
        realtime?.disconnect()
        flushJob?.cancel()
        super.onDestroy()
    }

    @SuppressLint("MissingPermission")
    private fun startLocation() {
        val interval = (DriverState.profile.value?.config?.locationIntervalSeconds ?: 4) * 1000L
        val request = LocationRequest.Builder(Priority.PRIORITY_HIGH_ACCURACY, interval)
            .setMinUpdateIntervalMillis(interval / 2)
            .build()
        runCatching {
            LocationServices.getFusedLocationProviderClient(this).requestLocationUpdates(request, locationCallback, Looper.getMainLooper())
        }
    }

    private fun onLocation(location: Location) {
        DriverState.setLocation(location.latitude, location.longitude)
        buffer.addLast(
            LocationPoint(
                location.latitude,
                location.longitude,
                (location.speed * 3.6).toInt(),
                location.bearing.toInt(),
                location.accuracy.toInt(),
            ),
        )
        // Internet yo'q paytda ham yig'iladi, lekin cheksiz emas
        while (buffer.size > 200) buffer.removeFirst()

        val order = DriverState.order.value
        val meter = taximeter
        if (order?.status == "in_progress" && meter != null) {
            if (meter.add(location.latitude, location.longitude, location.accuracy, location.time)) {
                DriverState.setMeters(meter.insideMeters.toLong(), meter.outsideMeters.toLong())
                saveTaximeter(order.id, meter)
            }
        }
    }

    private suspend fun flushLoop() {
        while (lifecycleScope.isActive) {
            delay((DriverState.profile.value?.config?.locationIntervalSeconds ?: 4) * 1000L)
            if (buffer.isEmpty()) continue
            val batch = buffer.toList()
            if (app.repository.sendLocations(batch)) {
                repeat(batch.size) { if (buffer.isNotEmpty()) buffer.removeFirst() }
            }
        }
    }

    private fun connectRealtime() {
        val profile = DriverState.profile.value ?: return
        val token = app.session.token ?: return
        realtime = Realtime(profile.config.reverb, token, profile.id, ::onEvent).also { it.connect() }
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
    private fun onOrderChanged(order: Order?) {
        if (order == null) {
            taximeter = null
            meterOrderId = null
            DriverState.setMeters(0, 0)
            prefs().edit().clear().apply()
            return
        }
        if (order.status == "in_progress" && meterOrderId != order.id) {
            taximeter = Taximeter(ServiceArea(DriverState.profile.value?.config?.serviceArea))
            meterOrderId = order.id
            DriverState.setMeters(0, 0)
        }
    }

    /** Jarayon o'ldirilsa ham taksometr yo'qolmasin */
    private fun saveTaximeter(orderId: Int, meter: Taximeter) {
        prefs().edit()
            .putInt("order", orderId)
            .putLong("inside", meter.insideMeters.toLong())
            .putLong("outside", meter.outsideMeters.toLong())
            .apply()
    }

    private fun restoreTaximeter() {
        val prefs = prefs()
        val orderId = prefs.getInt("order", 0)
        if (orderId == 0) return
        DriverState.setMeters(prefs.getLong("inside", 0), prefs.getLong("outside", 0))
    }

    private fun prefs() = getSharedPreferences("taximeter", Context.MODE_PRIVATE)

    private fun notification(): Notification = NotificationCompat.Builder(this, IbTaxiApp.CHANNEL_SERVICE)
        .setSmallIcon(R.drawable.ic_launcher)
        .setContentTitle(getString(R.string.service_title))
        .setContentText(getString(R.string.service_text))
        .setOngoing(true)
        .setContentIntent(
            PendingIntent.getActivity(this, 0, Intent(this, MainActivity::class.java), PendingIntent.FLAG_IMMUTABLE),
        )
        .build()

    companion object {
        private const val NOTIFICATION_ID = 1001
        private val gson = Gson()

        fun start(context: Context) = context.startForegroundService(Intent(context, DriverService::class.java))

        fun stop(context: Context) = context.stopService(Intent(context, DriverService::class.java))
    }
}
