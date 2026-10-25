package uz.dtaxi.client.data

import com.google.gson.annotations.SerializedName
import uz.dtaxi.core.data.ReverbConfig

data class LatLng(val lat: Double, val lng: Double)

data class Profile(
    val id: Int,
    val phone: String,
    val name: String?,
    val locale: String,
    @SerializedName("needs_name") val needsName: Boolean,
    val config: ClientConfig,
)

data class ClientConfig(
    @SerializedName("company_name") val companyName: String?,
    /** Haydovchi yoki dispetcher bilan aloqa — firma raqami */
    @SerializedName("support_phone") val supportPhone: String?,
    @SerializedName("map_style") val mapStyle: String,
    val reverb: ReverbConfig,
    @SerializedName("map_center") val mapCenter: LatLng?,
)

/** Manzil: qidiruv natijasi, xaritadagi nuqta yoki buyurtma so'rovi (null maydonlar yuborilmaydi) */
data class Place(
    val text: String?,
    val lat: Double,
    val lng: Double,
    @SerializedName("landmark_id") val landmarkId: Int? = null,
    val category: String? = null,
)

data class CodeRequest(val phone: String)

data class VerifyRequest(val phone: String, val code: String, @SerializedName("device_name") val deviceName: String)

data class VerifyResponse(val token: String, val client: Profile)

data class ProfileUpdate(val name: String? = null, val locale: String? = null)

data class QuoteRequest(val pickup: Place, val dropoff: Place?)

data class Quote(@SerializedName("branch_id") val branchId: Int, val tariffs: List<TariffQuote>)

data class TariffQuote(
    @SerializedName("tariff_class_id") val id: Int,
    val name: String,
    @SerializedName("is_delivery") val isDelivery: Boolean,
    /** "Qayerga" bo'lmasa null — eng kami ko'rsatiladi */
    val price: Long?,
    @SerializedName("min_price") val minPrice: Long,
)

data class OrderRequest(
    val pickup: Place,
    val dropoff: Place?,
    @SerializedName("tariff_class_id") val tariffClassId: Int?,
    val comment: String?,
)

data class ClientOrder(
    val id: Int,
    val status: String,
    val pickup: Place,
    val dropoff: Place?,
    @SerializedName("tariff_class") val tariffClass: TariffRef,
    val comment: String?,
    @SerializedName("price_estimate") val priceEstimate: Long?,
    @SerializedName("price_final") val priceFinal: Long?,
    val driver: OrderDriver?,
    val vehicle: OrderVehicle?,
    @SerializedName("can_cancel") val canCancel: Boolean,
    val rating: Int?,
    @SerializedName("created_at") val createdAt: String,
) {
    val isActive: Boolean get() = status in ACTIVE

    companion object {
        val ACTIVE = setOf("new", "searching", "assigned", "arrived", "in_progress")
    }
}

data class TariffRef(val id: Int, val name: String)

data class OrderDriver(val name: String, val callsign: String, val rating: Int, val location: LatLng?)

data class OrderVehicle(val brand: String, val model: String, val color: String, val plate: String)

data class CurrentOrders(val order: ClientOrder?, @SerializedName("to_rate") val toRate: ClientOrder?)

/** 409: faol buyurtma bor — server uni qaytaradi */
data class ActiveOrderConflict(val message: String?, val order: ClientOrder?)

data class CancelRequest(val reason: String?)

data class RateRequest(val rating: Int)

data class SavedAddress(val id: Int, val label: String?, val text: String, val lat: Double, val lng: Double)

data class AddressRequest(val label: String, val text: String, val lat: Double, val lng: Double)

data class ReverseResponse(val text: String?)
