package uz.dtaxi.core.ui

import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.text.input.OffsetMapping
import androidx.compose.ui.text.input.TransformedText
import androidx.compose.ui.text.input.VisualTransformation
import uz.dtaxi.core.data.Formats

/** "901234567" → "90 123 45 67": kursor raqamlar orasida to'g'ri siljiydi */
object PhoneVisualTransformation : VisualTransformation {
    override fun filter(text: AnnotatedString): TransformedText {
        val digits = text.text
        val out = Formats.formatPhoneNational(digits)
        val mapping = object : OffsetMapping {
            override fun originalToTransformed(offset: Int): Int =
                offset + listOf(2, 5, 7).count { offset > it }

            override fun transformedToOriginal(offset: Int): Int =
                (offset - out.take(offset).count { it == ' ' }).coerceIn(0, digits.length)
        }
        return TransformedText(AnnotatedString(out), mapping)
    }
}
