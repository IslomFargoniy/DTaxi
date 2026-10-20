package uz.ibtaxi.driver.ui

import uz.ibtaxi.driver.R
import androidx.compose.ui.unit.dp
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.Alignment
import androidx.compose.runtime.setValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.getValue
import androidx.compose.material3.SmallFloatingActionButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Icon
import androidx.compose.material.icons.filled.MyLocation
import androidx.compose.material.icons.filled.LocationSearching
import androidx.compose.material.icons.Icons
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.Box
import android.os.Bundle
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLifecycleOwner
import androidx.compose.ui.viewinterop.AndroidView
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import org.maplibre.android.camera.CameraPosition
import org.maplibre.android.camera.CameraUpdateFactory
import org.maplibre.android.geometry.LatLng
import org.maplibre.android.geometry.LatLngBounds
import org.maplibre.android.maps.MapLibreMap
import org.maplibre.android.maps.MapView
import org.maplibre.android.maps.Style
import org.maplibre.android.style.expressions.Expression
import org.maplibre.android.style.layers.CircleLayer
import org.maplibre.android.style.layers.LineLayer
import org.maplibre.android.style.layers.PropertyFactory
import org.maplibre.android.style.sources.GeoJsonSource
import org.maplibre.geojson.Feature
import org.maplibre.geojson.FeatureCollection
import org.maplibre.geojson.LineString
import org.maplibre.geojson.Point

data class MapPoint(val lat: Double, val lng: Double)

/**
 * Ilova ichidagi xarita (qaror #3): joylashuv, A/B nuqtalar va marshrut chizig'i. Uslub — serverdan (/map/style.json).
 */
@Composable
fun TaxiMap(
    styleUrl: String,
    me: MapPoint?,
    pickup: MapPoint?,
    dropoff: MapPoint?,
    route: List<List<Double>>?,
    modifier: Modifier = Modifier,
    stops: List<MapPoint> = emptyList(),
) {
    val context = LocalContext.current
    val mapView = remember { MapView(context).apply { onCreate(Bundle()) } }
    val holder = remember { arrayOfNulls<MapLibreMap>(1) }
    val lifecycle = LocalLifecycleOwner.current.lifecycle
    // Kuzatish rejimi: haydovchi xaritani surgach o'chadi, "joylashuvim" tugmasi qayta yoqadi (Yandex kabi)
    var follow by remember { mutableStateOf(true) }
    var recenter by remember { mutableIntStateOf(0) }

    DisposableEffect(lifecycle) {
        val observer = LifecycleEventObserver { _, event ->
            when (event) {
                Lifecycle.Event.ON_START -> mapView.onStart()
                Lifecycle.Event.ON_RESUME -> mapView.onResume()
                Lifecycle.Event.ON_PAUSE -> mapView.onPause()
                Lifecycle.Event.ON_STOP -> mapView.onStop()
                Lifecycle.Event.ON_DESTROY -> mapView.onDestroy()
                else -> Unit
            }
        }
        lifecycle.addObserver(observer)
        onDispose { lifecycle.removeObserver(observer) }
    }

    Box(modifier) {
    AndroidView(factory = {
        mapView.also { view ->
            view.getMapAsync { map ->
                holder[0] = map
                map.uiSettings.isRotateGesturesEnabled = false
                map.addOnCameraMoveStartedListener { reason ->
                    if (reason == MapLibreMap.OnCameraMoveStartedListener.REASON_API_GESTURE) follow = false
                }
                map.cameraPosition = CameraPosition.Builder().target(LatLng(40.3864, 71.7864)).zoom(13.0).build()
                map.setStyle(Style.Builder().fromUri(styleUrl)) { style -> addLayers(style) }
            }
        }
    }, modifier = Modifier.fillMaxSize())

    SmallFloatingActionButton(
        onClick = {
            follow = true
            recenter++
        },
        modifier = Modifier.align(Alignment.CenterEnd).padding(end = 12.dp),
        containerColor = MaterialTheme.colorScheme.surface,
    ) {
        Icon(
            if (follow) Icons.Default.MyLocation else Icons.Default.LocationSearching,
            contentDescription = stringResource(R.string.my_location),
            // GPS hali topilmagan — kulrang
            tint = when {
                me == null -> MaterialTheme.colorScheme.outline
                follow -> MaterialTheme.colorScheme.primary
                else -> MaterialTheme.colorScheme.onSurface
            },
        )
    }
    }

    LaunchedEffect(me, pickup, dropoff, route, recenter, stops) {
        val map = holder[0] ?: return@LaunchedEffect
        val style = map.style ?: return@LaunchedEffect
        update(style, me, pickup, dropoff, route, stops)
        if (!follow) return@LaunchedEffect

        // Tugma bosilganda — haydovchining o'ziga, uy raqamlari ko'rinadigan zoom'gacha
        if (recenter > 0 && me != null && pickup == null && dropoff == null) {
            map.animateCamera(CameraUpdateFactory.newLatLngZoom(LatLng(me.lat, me.lng), 17.0))
            return@LaunchedEffect
        }

        val points = listOfNotNull(me, pickup, dropoff) + stops
        if (points.size >= 2) {
            val bounds = LatLngBounds.Builder().apply { points.forEach { include(LatLng(it.lat, it.lng)) } }.build()
            map.easeCamera(CameraUpdateFactory.newLatLngBounds(bounds, 120))
        } else if (me != null) {
            map.easeCamera(CameraUpdateFactory.newLatLng(LatLng(me.lat, me.lng)))
        }
    }
}

private fun addLayers(style: Style) {
    style.addSource(GeoJsonSource("route"))
    style.addSource(GeoJsonSource("points"))
    style.addLayer(LineLayer("route", "route").withProperties(PropertyFactory.lineColor("#2563eb"), PropertyFactory.lineWidth(5f)))
    style.addLayer(
        CircleLayer("points", "points").withProperties(
            PropertyFactory.circleRadius(9f),
            PropertyFactory.circleStrokeWidth(3f),
            PropertyFactory.circleStrokeColor("#ffffff"),
            PropertyFactory.circleColor(
                Expression.match(
                    Expression.get("kind"),
                    Expression.literal("#16a34a"),
                    Expression.stop("me", "#16a34a"),
                    Expression.stop("pickup", "#2563eb"),
                    Expression.stop("dropoff", "#9333ea"),
                    Expression.stop("stop", "#f59e0b"),
                ),
            ),
        ),
    )
}

private fun update(style: Style, me: MapPoint?, pickup: MapPoint?, dropoff: MapPoint?, route: List<List<Double>>?, stops: List<MapPoint>) {
    val features = listOfNotNull(
        me?.let { feature(it, "me") },
        pickup?.let { feature(it, "pickup") },
        dropoff?.let { feature(it, "dropoff") },
    ) + stops.map { feature(it, "stop") }
    style.getSourceAs<GeoJsonSource>("points")?.setGeoJson(FeatureCollection.fromFeatures(features))
    val line = route?.takeIf { it.size >= 2 }?.map { Point.fromLngLat(it[0], it[1]) }
    style.getSourceAs<GeoJsonSource>("route")?.setGeoJson(
        FeatureCollection.fromFeatures(line?.let { listOf(Feature.fromGeometry(LineString.fromLngLats(it))) } ?: emptyList()),
    )
}

private fun feature(point: MapPoint, kind: String) =
    Feature.fromGeometry(Point.fromLngLat(point.lng, point.lat)).apply { addStringProperty("kind", kind) }
