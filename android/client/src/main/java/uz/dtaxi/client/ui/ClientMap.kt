package uz.dtaxi.client.ui

import android.os.Bundle
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Place
import androidx.compose.material3.Icon
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLifecycleOwner
import androidx.compose.ui.unit.dp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import org.maplibre.android.camera.CameraPosition
import org.maplibre.android.camera.CameraUpdateFactory
import org.maplibre.android.geometry.LatLngBounds
import org.maplibre.android.maps.MapLibreMap
import org.maplibre.android.maps.MapView
import org.maplibre.android.maps.Style
import org.maplibre.android.style.expressions.Expression
import org.maplibre.android.style.layers.CircleLayer
import org.maplibre.android.style.layers.PropertyFactory
import org.maplibre.android.style.sources.GeoJsonSource
import org.maplibre.geojson.Feature
import org.maplibre.geojson.FeatureCollection
import org.maplibre.geojson.Point
import uz.dtaxi.client.data.LatLng
import org.maplibre.android.geometry.LatLng as MapLatLng

/** O'zbekiston markazi — firma filiali hali noma'lum bo'lsa */
private val UZBEKISTAN = MapLatLng(41.38, 64.59)

/** Xarita buyruqlari ekrandan: joylashuvga o'tish, buyurtma nuqtalarini sig'dirish */
class MapController {
    internal var map: MapLibreMap? = null

    fun moveTo(point: LatLng, zoom: Double = 16.0) {
        map?.animateCamera(CameraUpdateFactory.newLatLngZoom(MapLatLng(point.lat, point.lng), zoom))
    }

    fun fit(points: List<LatLng>) {
        val map = map ?: return
        when {
            points.isEmpty() -> Unit
            points.size == 1 -> moveTo(points.first())
            else -> {
                val bounds = LatLngBounds.Builder().apply { points.forEach { include(MapLatLng(it.lat, it.lng)) } }.build()
                map.animateCamera(CameraUpdateFactory.newLatLngBounds(bounds, 160))
            }
        }
    }
}

/**
 * Mijoz xaritasi. picking — "Qayerdan" tanlash: markazda pin, xarita to'xtaganda onCenter(markaz).
 * Aks holda buyurtma nuqtalari (A, B) va haydovchi ko'rsatiladi.
 */
@Composable
fun ClientMap(
    styleUrl: String,
    initialCenter: LatLng?,
    controller: MapController,
    picking: Boolean,
    pickup: LatLng?,
    dropoff: LatLng?,
    driver: LatLng?,
    modifier: Modifier = Modifier,
    onCenter: (LatLng) -> Unit = {},
    onMoveStarted: () -> Unit = {},
) {
    val context = LocalContext.current
    val mapView = remember { MapView(context).apply { onCreate(Bundle()) } }
    val lifecycle = LocalLifecycleOwner.current.lifecycle
    val styleHolder = remember { arrayOfNulls<Style>(1) }
    // Tinglovchilar xarita bir marta yaratilganda o'rnatiladi — eng oxirgi qiymatlar shu orqali
    val state = remember { arrayOf<Any?>(picking, onCenter, onMoveStarted) }
    state[0] = picking
    state[1] = onCenter
    state[2] = onMoveStarted

    DisposableEffect(lifecycle) {
        var started = false
        var resumed = false
        val observer = LifecycleEventObserver { _, event ->
            when (event) {
                Lifecycle.Event.ON_START -> mapView.onStart().also { started = true }
                Lifecycle.Event.ON_RESUME -> mapView.onResume().also { resumed = true }
                Lifecycle.Event.ON_PAUSE -> mapView.onPause().also { resumed = false }
                Lifecycle.Event.ON_STOP -> mapView.onStop().also { started = false }
                else -> Unit
            }
        }
        lifecycle.addObserver(observer)
        onDispose {
            lifecycle.removeObserver(observer)
            if (resumed) mapView.onPause()
            if (started) mapView.onStop()
            mapView.onDestroy()
            controller.map = null
        }
    }

    Box(modifier) {
        AndroidView(factory = {
            mapView.also { view ->
                view.getMapAsync { map ->
                    controller.map = map
                    map.uiSettings.isRotateGesturesEnabled = false
                    map.uiSettings.isCompassEnabled = false
                    map.uiSettings.isAttributionEnabled = true
                    map.cameraPosition = CameraPosition.Builder()
                        .target(initialCenter?.let { MapLatLng(it.lat, it.lng) } ?: UZBEKISTAN)
                        .zoom(if (initialCenter != null) 14.0 else 5.0).build()
                    map.addOnCameraMoveStartedListener { reason ->
                        @Suppress("UNCHECKED_CAST")
                        if (reason == MapLibreMap.OnCameraMoveStartedListener.REASON_API_GESTURE) (state[2] as () -> Unit)()
                    }
                    map.addOnCameraIdleListener {
                        @Suppress("UNCHECKED_CAST")
                        if (state[0] == true) {
                            val target = map.cameraPosition.target ?: return@addOnCameraIdleListener
                            (state[1] as (LatLng) -> Unit)(LatLng(target.latitude, target.longitude))
                        }
                    }
                    map.setStyle(Style.Builder().fromUri(styleUrl)) { style ->
                        style.addSource(GeoJsonSource(SOURCE))
                        // A — yashil, B — qizil, haydovchi — firma rangi; oq hoshiya har ikki mavzuda ko'rinadi
                        style.addLayer(
                            CircleLayer("points", SOURCE).withProperties(
                                PropertyFactory.circleRadius(Expression.match(Expression.get("kind"), Expression.literal(9f), Expression.stop("driver", 11f))),
                                PropertyFactory.circleColor(
                                    Expression.match(
                                        Expression.get("kind"), Expression.color(android.graphics.Color.parseColor("#1FA463")),
                                        Expression.stop("dropoff", Expression.color(android.graphics.Color.parseColor("#D92D20"))),
                                        Expression.stop("driver", Expression.color(android.graphics.Color.parseColor("#111111"))),
                                    ),
                                ),
                                PropertyFactory.circleStrokeWidth(3f),
                                PropertyFactory.circleStrokeColor(android.graphics.Color.WHITE),
                            ),
                        )
                        styleHolder[0] = style
                        update(style, if (state[0] == true) null else pickup, dropoff, driver)
                    }
                }
            }
        }, modifier = Modifier.fillMaxSize())

        // Tanlash rejimi: pin markazda, uchi aynan markaz nuqtasida
        if (picking) {
            Icon(
                Icons.Default.Place,
                contentDescription = null,
                tint = Color(0xFF111111),
                modifier = Modifier.align(Alignment.Center).size(44.dp).offset(y = (-20).dp),
            )
        }
    }

    LaunchedEffect(picking, pickup, dropoff, driver) {
        styleHolder[0]?.let { update(it, if (picking) null else pickup, dropoff, driver) }
    }
}

private const val SOURCE = "client-points"

private fun update(style: Style, pickup: LatLng?, dropoff: LatLng?, driver: LatLng?) {
    val features = buildList {
        pickup?.let { add(feature(it, "pickup")) }
        dropoff?.let { add(feature(it, "dropoff")) }
        driver?.let { add(feature(it, "driver")) }
    }
    style.getSourceAs<GeoJsonSource>(SOURCE)?.setGeoJson(FeatureCollection.fromFeatures(features))
}

private fun feature(point: LatLng, kind: String) =
    Feature.fromGeometry(Point.fromLngLat(point.lng, point.lat)).apply { addStringProperty("kind", kind) }
