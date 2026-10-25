package uz.dtaxi.client.data

import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Path
import retrofit2.http.Query

/** Mijoz ilovasi API (qaror #71): /api/client/v1 */
interface Api {
    @POST("api/client/v1/auth/request-code")
    suspend fun requestCode(@Body body: CodeRequest): Response<Unit>

    @POST("api/client/v1/auth/verify")
    suspend fun verify(@Body body: VerifyRequest): Response<VerifyResponse>

    @POST("api/client/v1/auth/logout")
    suspend fun logout(): Response<Unit>

    @GET("api/client/v1/me")
    suspend fun me(): Response<Profile>

    @DELETE("api/client/v1/me")
    suspend fun deleteAccount(): Response<Unit>

    @PUT("api/client/v1/me")
    suspend fun updateProfile(@Body body: ProfileUpdate): Response<Profile>

    @POST("api/client/v1/device-token")
    suspend fun deviceToken(@Body body: TokenRequest): Response<Unit>

    @GET("api/client/v1/places")
    suspend fun places(@Query("q") query: String, @Query("lat") lat: Double?, @Query("lng") lng: Double?): Response<List<Place>>

    @GET("api/client/v1/places/reverse")
    suspend fun reverse(@Query("lat") lat: Double, @Query("lng") lng: Double): Response<ReverseResponse>

    @GET("api/client/v1/addresses")
    suspend fun addresses(): Response<List<SavedAddress>>

    @POST("api/client/v1/addresses")
    suspend fun saveAddress(@Body body: AddressRequest): Response<SavedAddress>

    @DELETE("api/client/v1/addresses/{id}")
    suspend fun deleteAddress(@Path("id") id: Int): Response<Unit>

    @POST("api/client/v1/quote")
    suspend fun quote(@Body body: QuoteRequest): Response<Quote>

    @POST("api/client/v1/orders")
    suspend fun createOrder(@Body body: OrderRequest): Response<ClientOrder>

    @GET("api/client/v1/orders/current")
    suspend fun current(): Response<CurrentOrders>

    @GET("api/client/v1/orders")
    suspend fun history(@Query("before_id") beforeId: Int?): Response<List<ClientOrder>>

    @POST("api/client/v1/orders/{id}/cancel")
    suspend fun cancel(@Path("id") id: Int, @Body body: CancelRequest): Response<ClientOrder>

    @POST("api/client/v1/orders/{id}/rate")
    suspend fun rate(@Path("id") id: Int, @Body body: RateRequest): Response<ClientOrder>
}
