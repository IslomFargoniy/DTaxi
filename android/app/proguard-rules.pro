# API modellari Gson orqali (maydon nomlari @SerializedName bilan, lekin klasslar saqlanishi kerak)
-keep class uz.ibtaxi.driver.data.** { *; }
# Taksometr holati diskka Gson bilan yoziladi
-keep class uz.ibtaxi.driver.taximeter.MeterState { *; }
-keepattributes Signature, *Annotation*, InnerClasses, EnclosingMethod
# Retrofit
-keep,allowobfuscation,allowshrinking interface retrofit2.Call
-keep,allowobfuscation,allowshrinking class retrofit2.Response
-keep,allowobfuscation,allowshrinking class kotlin.coroutines.Continuation
# Pusher (Reverb)
-keep class com.pusher.** { *; }
-dontwarn com.pusher.**
-dontwarn org.slf4j.**
-dontwarn okhttp3.internal.platform.**
-dontwarn org.conscrypt.**
-dontwarn org.bouncycastle.**
-dontwarn org.openjsse.**
