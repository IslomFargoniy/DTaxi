package uz.ibtaxi.driver.data

import com.google.gson.JsonObject
import com.google.gson.annotations.SerializedName

data class LoginRequest(val phone: String, val password: String, @SerializedName("device_name") val deviceName: String)

data class LoginResponse(val token: String, val driver: DriverProfile)

data class DriverProfile(
    val id: Int,
    @SerializedName("user_id") val userId: Int,
    val callsign: String,
    val name: String,
    val phone: String?,
    val locale: String,
    val status: String,
    val balance: Long,
    @SerializedName("min_balance") val minBalance: Long,
    val rating: Int,
    @SerializedName("is_approved") val isApproved: Boolean,
    @SerializedName("blocked_until") val blockedUntil: String?,
    val vehicle: Vehicle?,
    val config: AppConfig,
    val tariffs: List<Tariff>,
)

data class Vehicle(val brand: String, val model: String, val color: String, @SerializedName("plate_number") val plateNumber: String)

data class AppConfig(
    @SerializedName("map_style") val mapStyle: String,
    val reverb: ReverbConfig,
    @SerializedName("location_interval_seconds") val locationIntervalSeconds: Int,
    @SerializedName("offer_seconds") val offerSeconds: Int,
    /** GeoJSON (Polygon / MultiPolygon) — taksometr shahar tashqarisini ajratishi uchun */
    @SerializedName("service_area") val serviceArea: JsonObject?,
    val payments: PaymentsConfig? = null,
)

/** Firma Click/Payme bilan shartnoma qilgan bo'lsa — balansni to'ldirish havolalari uchun */
data class PaymentsConfig(val click: ClickConfig?, val payme: PaymeConfig?)

data class ClickConfig(@SerializedName("service_id") val serviceId: String, @SerializedName("merchant_id") val merchantId: String?)

data class PaymeConfig(@SerializedName("merchant_id") val merchantId: String)

data class ReverbConfig(val key: String, val host: String, val port: Int, val scheme: String)

/** Server Tariff::snapshot() bilan bir xil maydonlar — narx formulasi ilovada ham ishlaydi */
data class Tariff(
    @SerializedName("tariff_class_id") val tariffClassId: Int,
    val name: String,
    @SerializedName("base_fare") val baseFare: Long,
    @SerializedName("base_km") val baseKm: String,
    @SerializedName("per_km") val perKm: Long,
    @SerializedName("rounding_step") val roundingStep: Long,
    @SerializedName("waiting_enabled") val waitingEnabled: Boolean,
    @SerializedName("waiting_free_min") val waitingFreeMin: Int,
    @SerializedName("waiting_per_min") val waitingPerMin: Long,
    @SerializedName("night_enabled") val nightEnabled: Boolean,
    @SerializedName("night_from") val nightFrom: String,
    @SerializedName("night_to") val nightTo: String,
    @SerializedName("night_coeff") val nightCoeff: String,
    @SerializedName("outside_enabled") val outsideEnabled: Boolean,
    @SerializedName("outside_per_km") val outsidePerKm: Long,
)

data class Place(val text: String?, val lat: Double?, val lng: Double?)

data class Order(
    val id: Int,
    val status: String,
    val client: OrderClient?,
    @SerializedName("tariff_class") val tariffClass: OrderTariffClass?,
    val pickup: Place,
    val dropoff: Place?,
    val comment: String?,
    @SerializedName("price_estimate") val priceEstimate: Long?,
    @SerializedName("price_final") val priceFinal: Long?,
    @SerializedName("price_breakdown") val priceBreakdown: Map<String, Long>?,
    @SerializedName("arrived_at") val arrivedAt: String?,
)

data class OrderClient(val id: Int, val phone: String, val name: String?)

data class OrderTariffClass(val id: Int, val name: String, @SerializedName("is_delivery") val isDelivery: Boolean)

data class OrderEnvelope(val order: Order?)

/** Efirdan taklif — Reverb va FCM bir xil ma'lumot yuboradi (OrderOffered::payload) */
data class Offer(
    @SerializedName("offer_id") val offerId: Int,
    @SerializedName("order_id") val orderId: Int,
    @SerializedName("is_direct") val isDirect: Boolean,
    @SerializedName("distance_m") val distanceM: Int?,
    @SerializedName("pickup_text") val pickupText: String,
    @SerializedName("pickup_lat") val pickupLat: Double,
    @SerializedName("pickup_lng") val pickupLng: Double,
    @SerializedName("dropoff_text") val dropoffText: String?,
    @SerializedName("tariff_class") val tariffClass: String,
    @SerializedName("is_delivery") val isDelivery: Boolean,
    @SerializedName("price_estimate") val priceEstimate: Long?,
    val comment: String?,
    @SerializedName("expires_in") val expiresIn: Int,
)

data class LocationPoint(val lat: Double, val lng: Double, val speed: Int?, val heading: Int?, val accuracy: Int?)

data class LocationBatch(val points: List<LocationPoint>)

data class ShiftRequest(val online: Boolean)

data class ShiftResponse(val status: String)

data class CompleteRequest(@SerializedName("inside_m") val insideM: Long, @SerializedName("outside_m") val outsideM: Long)

data class CancelRequest(val reason: String)

data class TokenRequest(val token: String)

data class RouteResponse(
    @SerializedName("distance_m") val distanceM: Int,
    @SerializedName("duration_s") val durationS: Int,
    val geometry: List<List<Double>>?,
    val estimated: Boolean,
)

data class Transaction(
    val type: String,
    val source: String,
    val amount: Long,
    @SerializedName("balance_after") val balanceAfter: Long,
    val comment: String?,
    @SerializedName("created_at") val createdAt: String?,
)

data class BalanceResponse(val balance: Long, val items: List<Transaction>)

data class ChatMessage(
    val id: Int,
    val mine: Boolean,
    val body: String?,
    @SerializedName("attachment_url") val attachmentUrl: String?,
    @SerializedName("created_at") val createdAt: String?,
)

data class ChatRequest(val body: String, @SerializedName("order_id") val orderId: Int?)

data class AppVersion(
    @SerializedName("version_code") val versionCode: Int,
    @SerializedName("min_version_code") val minVersionCode: Int,
    val url: String?,
)

data class ApiError(val message: String?)
