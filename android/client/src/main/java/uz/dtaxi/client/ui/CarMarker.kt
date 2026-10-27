package uz.dtaxi.client.ui

import android.graphics.Bitmap
import android.graphics.Canvas
import android.graphics.Paint
import android.graphics.Path
import android.graphics.RectF
import uz.dtaxi.client.data.LatLng
import kotlin.math.atan2
import kotlin.math.cos
import kotlin.math.hypot
import kotlin.math.sin

/**
 * Xaritadagi haydovchi belgisi — yuqoridan ko'rinishdagi mashina, tumshug'i shimolga (operator panelidagi
 * resources/js/lib/car-marker.ts bilan bir xil shakl). Burish xarita qatlamida (icon-rotate) qilinadi.
 */
object CarMarker {
    // 34x62 birlik: mashina 23x54, atrofida soya uchun joy
    private const val W = 34f
    private const val H = 62f

    /** Mashina uzunligi ~40 dp */
    fun bitmap(density: Float, body: Int): Bitmap {
        val scale = density * 40f / 54f
        val bitmap = Bitmap.createBitmap((W * scale).toInt(), (H * scale).toInt(), Bitmap.Config.ARGB_8888)
        val canvas = Canvas(bitmap)
        canvas.translate(bitmap.width / 2f, bitmap.height / 2f)
        canvas.scale(scale, scale)

        val outline = 0xFF111111.toInt()
        val glass = 0xFF1F2329.toInt()
        val fill = Paint(Paint.ANTI_ALIAS_FLAG)
        fun rect(x: Float, y: Float, w: Float, h: Float, r: Float, color: Int) {
            fill.color = color
            canvas.drawRoundRect(RectF(x, y, x + w, y + h), r, r, fill)
        }

        // G'ildiraklar va kuzov soya bilan — har qanday xarita fonida ajralib tursin
        val shadow = Paint(Paint.ANTI_ALIAS_FLAG).apply {
            color = outline
            setShadowLayer(1.6f, 0f, 1f, 0x59000000)
        }
        val body0 = RectF(-11.5f, -27f, 11.5f, 27f)
        canvas.drawRoundRect(body0, 8.5f, 8.5f, shadow)
        rect(-13.5f, -17f, 3f, 8f, 1.2f, outline)
        rect(10.5f, -17f, 3f, 8f, 1.2f, outline)
        rect(-13.5f, 12f, 3f, 8f, 1.2f, outline)
        rect(10.5f, 12f, 3f, 8f, 1.2f, outline)
        fill.color = body
        canvas.drawRoundRect(body0, 8.5f, 8.5f, fill)
        val stroke = Paint(Paint.ANTI_ALIAS_FLAG).apply {
            style = Paint.Style.STROKE
            strokeWidth = 1.8f
            color = outline
        }
        canvas.drawRoundRect(body0, 8.5f, 8.5f, stroke)

        fill.color = glass
        canvas.drawPath(
            Path().apply {
                moveTo(-8.5f, -12f); quadTo(0f, -16.5f, 8.5f, -12f); lineTo(7.2f, -5.5f); quadTo(0f, -7.5f, -7.2f, -5.5f); close()
            },
            fill,
        )
        rect(-7.4f, -4.5f, 14.8f, 16f, 2.5f, body)
        stroke.apply { strokeWidth = 1f; color = (glass and 0x00FFFFFF) or 0x59000000 }
        canvas.drawRoundRect(RectF(-7.4f, -4.5f, 7.4f, 11.5f), 2.5f, 2.5f, stroke)
        fill.color = glass
        canvas.drawPath(
            Path().apply {
                moveTo(-7.2f, 14f); quadTo(0f, 15.8f, 7.2f, 14f); lineTo(8f, 19f); quadTo(0f, 21f, -8f, 19f); close()
            },
            fill,
        )
        rect(-9f, -26f, 4f, 2.2f, 1f, 0xFFFFF6C8.toInt())
        rect(5f, -26f, 4f, 2.2f, 1f, 0xFFFFF6C8.toInt())
        rect(-9f, 23.8f, 4f, 2f, 1f, 0xFFD23B2A.toInt())
        rect(5f, 23.8f, 4f, 2f, 1f, 0xFFD23B2A.toInt())
        rect(-14.5f, -8.5f, 3f, 2f, 1f, outline)
        rect(11.5f, -8.5f, 3f, 2f, 1f, outline)
        return bitmap
    }

    /** Ikki nuqta orasidagi yo'nalish (shimoldan soat yo'nalishida, gradus) */
    fun bearing(from: LatLng, to: LatLng): Float {
        val rad = Math.PI / 180
        val dLng = (to.lng - from.lng) * rad
        val y = sin(dLng) * cos(to.lat * rad)
        val x = cos(from.lat * rad) * sin(to.lat * rad) - sin(from.lat * rad) * cos(to.lat * rad) * cos(dLng)
        return ((Math.toDegrees(atan2(y, x)) + 360) % 360).toFloat()
    }

    fun distanceMeters(a: LatLng, b: LatLng): Double =
        hypot((b.lat - a.lat) * 111_320, (b.lng - a.lng) * 111_320 * cos(Math.toRadians(a.lat)))

    /** Burchakni eng qisqa yo'l bilan oraliq qiymat (350° → 10° — 20° burilish, 340° emas) */
    fun lerpAngle(from: Float, to: Float, f: Float): Float {
        val delta = ((to - from + 540f) % 360f) - 180f
        return (from + delta * f + 360f) % 360f
    }
}
