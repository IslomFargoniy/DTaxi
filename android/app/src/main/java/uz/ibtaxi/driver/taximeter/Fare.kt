package uz.ibtaxi.driver.taximeter

import uz.ibtaxi.driver.data.Tariff
import java.time.LocalTime
import kotlin.math.ceil
import kotlin.math.max
import kotlin.math.roundToLong

data class FareResult(val total: Long, val base: Long, val distance: Long, val outside: Long, val waiting: Long, val night: Long)

/**
 * Narx formulasi — serverdagi App\Services\Pricing\FareCalculator bilan aynan bir xil (reja §5b).
 * Ilova taksometrda jonli narxni ko'rsatadi; yakuniy narxni server hisoblaydi.
 */
object Fare {
    fun calculate(tariff: Tariff, insideMeters: Long, outsideMeters: Long = 0, waitingSeconds: Long = 0, startedAt: LocalTime? = null): FareResult {
        val includedKm = tariff.baseKm.toDouble()
        val insideKm = insideMeters / 1000.0

        val distance: Long
        var outside = 0L
        if (tariff.outsideEnabled) {
            distance = (max(0.0, insideKm - includedKm) * tariff.perKm).roundToLong()
            outside = (outsideMeters / 1000.0 * tariff.outsidePerKm).roundToLong()
        } else {
            distance = (max(0.0, insideKm + outsideMeters / 1000.0 - includedKm) * tariff.perKm).roundToLong()
        }

        var waiting = 0L
        if (tariff.waitingEnabled) {
            val paidMinutes = max(0L, ceil(waitingSeconds / 60.0).toLong() - tariff.waitingFreeMin)
            waiting = paidMinutes * tariff.waitingPerMin
        }

        val subtotal = tariff.baseFare + distance + outside + waiting
        var night = 0L
        if (tariff.nightEnabled && startedAt != null && isNight(startedAt, tariff.nightFrom, tariff.nightTo)) {
            night = (subtotal * (tariff.nightCoeff.toDouble() - 1)).roundToLong()
        }

        val step = max(1L, tariff.roundingStep)
        val total = ceil((subtotal + night).toDouble() / step).toLong() * step

        return FareResult(total, tariff.baseFare, distance, outside, waiting, night)
    }

    private fun isNight(at: LocalTime, from: String, to: String): Boolean {
        val f = LocalTime.parse(from.take(5))
        val t = LocalTime.parse(to.take(5))
        val time = at.withSecond(0).withNano(0)
        return if (f <= t) time >= f && time < t else time >= f || time < t
    }
}
