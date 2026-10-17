package uz.ibtaxi.driver.navigation

import uz.ibtaxi.driver.data.NavStep

/**
 * Manevr matni (o'zbek / rus): banner va ovoz uchun.
 */
object Instructions {
    fun text(step: NavStep, locale: String, meters: Int? = null): String {
        val ru = locale == "ru"
        val action = action(step, ru)
        val street = step.name.takeIf { it.isNotBlank() && step.type != "arrive" }
        val where = when {
            street == null -> ""
            ru -> " на $street"
            else -> ", $street"
        }
        return when (meters) {
            null -> action.replaceFirstChar { it.uppercase() } + where
            else -> if (ru) "Через $meters метров $action$where" else "$meters metrdan keyin $action$where"
        }
    }

    private fun action(step: NavStep, ru: Boolean): String {
        val m = step.modifier
        return when (step.type) {
            "arrive" -> if (ru) "вы прибыли" else "manzilga yetib keldingiz"
            "roundabout", "rotary" -> {
                val exit = step.exit ?: 1
                if (ru) "на круге $exit-й съезд" else "aylanmadan $exit-chiqish"
            }
            else -> when (m) {
                "left" -> if (ru) "поверните налево" else "chapga buriling"
                "right" -> if (ru) "поверните направо" else "o'ngga buriling"
                "slight left" -> if (ru) "держитесь левее" else "chaproqqa yuring"
                "slight right" -> if (ru) "держитесь правее" else "o'ngroqqa yuring"
                "sharp left" -> if (ru) "резко налево" else "keskin chapga"
                "sharp right" -> if (ru) "резко направо" else "keskin o'ngga"
                "uturn" -> if (ru) "развернитесь" else "orqaga buriling"
                else -> if (ru) "прямо" else "to'g'riga"
            }
        }
    }
}
